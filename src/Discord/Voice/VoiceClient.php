<?php

/*
 * This file is apart of the DiscordPHP project.
 *
 * Copyright (c) 2016 David Cole <david@team-reflex.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord\Voice;

use Discord\Exceptions\DCANotFoundException;
use Discord\Exceptions\FFmpegNotFoundException;
use Discord\Exceptions\FileNotFoundException;
use Discord\Parts\Channel\Channel;
use Discord\WSClient\Factory as WsFactory;
use Discord\WSClient\WebSocket as WS;
use Discord\WebSockets\WebSocket;
use Evenement\EventEmitter;
use Ratchet\WebSocket\Version\RFC6455\Frame;
use React\ChildProcess\Process;
use React\Datagram\Factory as DatagramFactory;
use React\Datagram\Socket;
use React\Dns\Resolver\Factory as DNSFactory;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Stream\Stream;

/**
 * The Discord voice client.
 */
class VoiceClient extends EventEmitter
{
    /**
     * The DCA binary name that we will use.
     *
     * @var string The DCA binary name that will be run.
     */
    protected $dca;

    /**
     * The ReactPHP event loop.
     *
     * @var LoopInterface The ReactPHP event loop that will run everything.
     */
    protected $loop;

    /**
     * The main WebSocket instance.
     *
     * @var WebSocket The main WebSocket client.
     */
    protected $mainWebsocket;

    /**
     * The voice WebSocket instance.
     *
     * @var WS The voice WebSocket client.
     */
    protected $voiceWebsocket;

    /**
     * The UDP client.
     *
     * @var Socket The voiceUDP client.
     */
    protected $client;

    /**
     * The Channel that we are connecting to.
     *
     * @var Channel The channel that we are going to connect to.
     */
    protected $channel;

    /**
     * Data from the main WebSocket.
     *
     * @var array Information required for the voice WebSocket.
     */
    protected $data;

    /**
     * The Voice WebSocket endpoint.
     *
     * @var string The endpoint the Voice WebSocket and UDP client will connect to.
     */
    protected $endpoint;

    /**
     * The port the UDP client will use.
     *
     * @var int The port that the UDP client will connect to.
     */
    protected $udpPort;

    /**
     * The UDP heartbeat interval.
     *
     * @var int How often we send a heartbeat packet.
     */
    protected $heartbeat_interval;

    /**
     * The SSRC value.
     *
     * @var int The SSRC value used for RTP.
     */
    protected $ssrc;

    /**
     * The sequence of audio packets being sent.
     *
     * @var int The sequence of audio packets.
     */
    protected $seq = 0;

    /**
     * The timestamp of the last packet.
     *
     * @var int The timestamp the last packet was constructed.
     */
    protected $timestamp = 0;

    /**
     * The Voice WebSocket mode.
     *
     * @var string The voice mode.
     */
    protected $mode = 'plain';

    /**
     * Are we currently set as speaking?
     *
     * @var bool Whether we are speaking or not.
     */
    protected $speaking = false;

    /**
     * Have we sent the login frame yet?
     *
     * @var bool Whether we have sent the login frame.
     */
    protected $sentLoginFrame = false;

    /**
     * The time we started sending packets.
     *
     * @var epoch The time we started sending packets.
     */
    protected $startTime;

    /**
     * The stream time of the last packet.
     *
     * @var int The time we sent the last packet.
     */
    protected $streamTime = 0;

    /**
     * The volume percentage the audio will be encoded with.
     *
     * @var int The volume percentage that the audio will be encoded in.
     */
    public $volume = 70;

    /**
     * Constructs the Voice Client instance.
     *
     * @param WebSocket     $websocket The main WebSocket client.
     * @param LoopInterface $loop      The ReactPHP event loop.
     * @param Channel       $channel   The channel we are connecting to.
     * @param array         $data      More information related to the voice client.
     *
     * @return void
     */
    public function __construct(WebSocket $websocket, LoopInterface &$loop, Channel $channel, $data)
    {
        $this->mainWebsocket = $websocket;
        $this->channel = $channel;
        $this->data = $data;
        $this->endpoint = str_replace([':80', ':443'], '', $data['endpoint']);

        $this->checkForFFmpeg();
        $this->checkForDCA();

        $this->loop = $this->initSockets($loop);
    }

    /**
     * Initilizes the WebSocket and UDP socket.
     *
     * @return void
     */
    public function initSockets($loop)
    {
        $wsfac = new WsFactory($loop);
        $resolver = (new DNSFactory())->createCached('8.8.8.8', $loop);
        $udpfac = new DatagramFactory($loop, $resolver);

        $wsfac("wss://{$this->endpoint}")->then(function (WS $ws) use ($udpfac, &$loop) {
            $this->voiceWebsocket = $ws;

            $firstPack = true;
            $ip = $port = '';

            $discoverUdp = function ($message) use (&$ws, &$discoverUdp, $udpfac, &$firstPack, &$ip, &$port, &$loop) {
                $data = json_decode($message);

                if ($data->op == 2) {
                    $ws->removeListener('message', $discoverUdp);

                    $this->udpPort = $data->d->port;
                    $this->heartbeat_interval = $data->d->heartbeat_interval;
                    $this->ssrc = $data->d->ssrc;

                    $loop->addPeriodicTimer($this->heartbeat_interval / 1000, function () {
                        $this->send([
                            'op' => 3,
                            'd' => null,
                        ]);
                    });

                    $buffer = new Buffer(70);
                    $buffer->writeUInt32BE($this->ssrc, 3);

                    $udpfac->createClient("{$this->endpoint}:{$this->udpPort}")->then(function (Socket $client) use (&$ws, &$firstPack, &$ip, &$port, $buffer, &$loop) {
                        $this->client = $client;

                        $loop->addTimer(0.1, function () use (&$client, $buffer) {
                            $client->send((string) $buffer);
                        });

                        $client->on('error', function ($e) {
                            $this->emit('error', [$e]);
                        });

                        $client->on('message', function ($message) use (&$ws, &$firstPack, &$ip, &$port) {
                            if ($firstPack) {
                                $message = (string) $message;
                                // let's get our IP
                                $ip_start = 4;
                                $ip = substr($message, $ip_start);
                                $ip_end = strpos($ip, "\x00");
                                $ip = substr($ip, 0, $ip_end);

                                // now the port!
                                $port = substr($message, strlen($message) - 2);
                                $port = unpack('v', $port)[1];

                                $payload = [
                                    'op' => 1,
                                    'd' => [
                                        'protocol' => 'udp',
                                        'data' => [
                                            'address' => $ip,
                                            'port' => (int) $port,
                                            'mode' => $this->mode,
                                        ],
                                    ],
                                ];

                                $this->send($payload);

                                $firstPack = false;
                            }
                        });
                    }, function ($e) {
                        $this->emit('error', [$e]);
                    });
                }
            };

            $ws->on('message', $discoverUdp);
            $ws->on('message', function ($message) {
                $data = json_decode($message);

                switch ($data->op) {
                    case 4: // ready
                        $this->ready = true;
                        $this->mode = $data->d->mode;
                        $this->emit('ready', [$this]);
                        break;
                }
            });

            if (! $this->sentLoginFrame) {
                $this->send([
                    'op' => 0,
                    'd' => [
                        'server_id' => $this->channel->guild_id,
                        'user_id' => $this->data['user_id'],
                        'session_id' => $this->data['session'],
                        'token' => $this->data['token'],
                    ],
                ]);
                $this->sentLoginFrame = true;
            }
        }, function ($e) {
            $this->emit('error', [$e]);
        });

        return $loop;
    }

    /**
     * Plays a file on the voice stream.
     *
     * @param string $file     The file to play.
     * @param int    $channels How many audio channels to encode with.
     *
     * @return \React\Promise\Promise
     *
     * @throws FileNotFoundException Thrown when the file specified could not be found.
     */
    public function playFile($file, $channels = 2)
    {
        $deferred = new Deferred();

        if (! file_exists($file)) {
            $deferred->reject(new FileNotFoundException("Could not find the file \"{$file}\"."));

            return $deferred->promise();
        }

        $process = $this->dcaConvert($file);
        $buffer = '';

        $process->start($this->loop);
        $process->stdout->pause();
        $process->on('exit', function ($code, $term) use ($deferred) {
            if ($code === 0) {
                $deferred->resolve(true);
            } else {
                $deferred->reject();
            }
        });

        $count = 0;
        $length = 20;
        $noData = false;
        $noDataHeader = false;

        $this->setSpeaking(true);

        $processff2opus = function () use (&$processff2opus, $length, $process, &$noData, &$noDataHeader, $deferred, $count) {
            $header = @fread($process->stdout->stream, 2);

            if (! $header) {
                if ($noDataHeader) {
                    $this->setSpeaking(false);
                    $deferred->resolve(true);
                } else {
                    $noDataHeader = true;
                    $this->loop->addTimer($length / 100, function () use (&$processff2opus) {
                        $processff2opus();
                    });
                }

                return;
            }

            $opusLength = unpack('v', $header);
            $opusLength = reset($opusLength);
            $buffer = fread($process->stdout->stream, $opusLength);

            if (! $buffer) {
                if ($noData) {
                    $this->setSpeaking(false);
                    $deferred->resolve();
                } else {
                    $noData = true;
                    $this->loop->addTimer($length / 100, function () use (&$processff2opus) {
                        $processff2opus();
                    });
                }

                return;
            }

            if (! $this->speaking) {
                $this->setSpeaking(true);
            }

            if (strlen($buffer) !== $opusLength) {
                $newbuff = new Buffer($opusLength);
                $newbuff->write($buffer, 0);
                $buffer = (string) $newbuff;
            }

            ++$count;

            $this->sendBuffer($buffer);

            if (($this->seq + 1) < 65535) {
                ++$this->seq;
            } else {
                $this->seq = 0;
            }

            if (($this->timestamp + 960) < 4294967295) {
                $this->timestamp += 960;
            } else {
                $this->timestamp = 0;
            }

            $next = $this->startTime + ($count * $length);
            $this->streamTime = $count * $length;

            $this->loop->addTimer($length / 1000, function () use (&$processff2opus) {
                $processff2opus();
            });
        };

        $processff2opus();

        return $deferred->promise();
    }

    /**
     * Plays a PHP resource stream.
     *
     * @param resource $stream   The stream to be encoded and sent.
     * @param int      $channels How many audio channels to encode with.
     *
     * @return \React\Promise\Promise
     *
     * @throws \RuntimeException Thrown when the stream passed to playRawStream is not a valid resource.
     */
    public function playRawStream($stream, $channels = 2)
    {
        $deferred = new Deferred();

        if (! is_resource($stream)) {
            $deferred->reject(new \RuntimeException('The stream passed to playRawStream was not an instance of resource.'));

            return $deferred->promise();
        }

        // At the moment DCA does not support piping so we can't pipe
        // a raw stream in at the moment.
        $deferred->reject(new \RuntimeException('DCA does not support piping audio at the moment.'));

        return $deferred->promise();
    }

    /**
     * Sends a buffer to the UDP socket.
     *
     * @param string $data     The data to send to the UDP server.
     * @param int    $channels How many audio channels to encode with.
     *
     * @return void
     */
    public function sendBuffer($data)
    {
        $packet = new VoicePacket($data, $this->ssrc, $this->seq, $this->timestamp);
        $this->client->send((string) $packet);

        $this->emit('packet-sent', [$packet]);
    }

    /**
     * Sets the speaking value of the client.
     *
     * @param bool $speaking Whether the client is speaking or not.
     *
     * @return bool Whether the client is speaking or not.
     */
    public function setSpeaking($speaking = true)
    {
        if ($this->speaking == $speaking) {
            return $speaking;
        }

        $this->send([
            'op' => 5,
            'd' => [
                'speaking' => $speaking,
                'delay' => 0,
            ],
        ]);

        $this->speaking = $speaking;

        return $speaking;
    }

    /**
     * Sends a message to the voice websocket.
     *
     * @param array $data The data to send to the voice WebSocket.
     *
     * @return void
     */
    public function send(array $data)
    {
        $frame = new Frame(json_encode($data), true);
        $this->voiceWebsocket->send($frame);
    }

    /**
     * Checks if FFmpeg is installed.
     *
     * @return bool Whether FFmpeg is installed or not.
     *
     * @throws \Discord\Exceptions\FFmpegNotFoundException Thrown when FFmpeg is not found.
     */
    public function checkForFFmpeg()
    {
        $binaries = [
            'ffmpeg',
        ];

        foreach ($binaries as $binary) {
            $output = shell_exec("which {$binary}");

            if (! empty($output)) {
                return;
            }
        }

        throw new FFmpegNotFoundException('No FFmpeg binary was found.');
    }

    /**
     * Checks if DCA is installed.
     *
     * @return bool Whether DCA is installed or not.
     *
     * @throws \Discord\Exceptions\DCANotFoundException Thrown when DCA is not found.
     */
    public function checkForDCA()
    {
        $binaries = [
            'dca',
            'ff2opus',
        ];

        foreach ($binaries as $binary) {
            $output = shell_exec("which {$binary}");

            if (! empty($output)) {
                $this->dca = $binary;

                return;
            }
        }

        throw new DCANotFoundException('No DCA binary was found.');
    }

    /**
     * Converts a file with DCA.
     *
     * @param string $filename The file name that will be converted
     *
     * @return Process A ReactPHP Child Process
     */
    public function dcaConvert($filename)
    {
        if (! file_exists($filename)) {
            return;
        }

        $filename = str_replace([' '], '\\ ', $filename);

        return new Process("{$this->dca} {$filename}");
    }
}
