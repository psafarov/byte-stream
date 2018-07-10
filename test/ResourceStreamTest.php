<?php

namespace Amp\ByteStream\Test;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\PendingReadError;
use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\ByteStream\StreamException;
use Amp\Loop;
use Concurrent\Task;
use PHPUnit\Framework\TestCase;

class ResourceStreamTest extends TestCase
{
    private const LARGE_MESSAGE_SIZE = 1 << 20; // 1 MB

    public function getStreamPair($outputChunkSize = null, $inputChunkSize = 8192): array
    {
        $domain = \stripos(PHP_OS, "win") === 0 ? STREAM_PF_INET : STREAM_PF_UNIX;
        [$left, $right] = @\stream_socket_pair($domain, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);

        $a = new ResourceOutputStream($left, $outputChunkSize);
        $b = new ResourceInputStream($right, $inputChunkSize);

        return [$a, $b];
    }

    public function testLargePayloads(): void
    {
        Task::await(Task::async(function () {
            [$a, $b] = $this->getStreamPair();

            $message = \str_repeat(".", self::LARGE_MESSAGE_SIZE);

            // TODO: Rethrow
            Task::async(function () use ($a, $message) {
                $a->end($message);
            });

            $received = "";
            while (null !== $chunk = $b->read()) {
                $received .= $chunk;
            }

            $this->assertSame($message, $received);
        }));
    }

    public function testManySmallPayloads(): void
    {
        Task::await(Task::async(function () {
            [$a, $b] = $this->getStreamPair();

            $message = \str_repeat(".", 8192 /* default chunk size */);

            // TODO: Rethrow
            Task::async(function () use ($a, $message) {
                for ($i = 0; $i < 128; $i++) {
                    $a->write($message);
                }

                $a->end();
            });

            $received = "";
            while (null !== $chunk = $b->read()) {
                $received .= $chunk;
            }

            $this->assertSame(\str_repeat($message, 128), $received);
        }));
    }

    public function testThrowsOnExternallyShutdownStreamWithLargePayload(): void
    {
        $this->markTestSkipped("Currently hangs");
        $this->expectException(StreamException::class);

        Task::await(Task::async(function () {
            try { /* prevent crashes with phpdbg due to SIGPIPE not being handled... */
                Loop::onSignal(\defined("SIGPIPE") ? SIGPIPE : 13, function () {
                });
            } catch (Loop\UnsupportedFeatureException $e) {
            }

            [$a, $b] = $this->getStreamPair();

            $message = \str_repeat(".", self::LARGE_MESSAGE_SIZE);
            $writePromise = Task::async([$a, 'write'], [$message]);

            $b->read();
            $b->close();

            Task::await($writePromise);
        }));
    }

    public function testThrowsOnExternallyShutdownStreamWithSmallPayloads(): void
    {
        $this->markTestSkipped("Currently hangs");
        $this->expectException(StreamException::class);

        Task::await(Task::async(function () {
            try { /* prevent crashes with phpdbg due to SIGPIPE not being handled... */
                Loop::onSignal(\defined("SIGPIPE") ? SIGPIPE : 13, function () {
                });
            } catch (Loop\UnsupportedFeatureException $e) {
            }

            [$a, $b] = $this->getStreamPair();

            $message = \str_repeat(".", 8192 /* default chunk size */);

            for ($i = 0; $i < 128; $i++) {
                $lastWritePromise = Task::async([$a, 'write'], [$message]);
            }

            $b->read();
            $b->close();

            Task::await($lastWritePromise);
        }));
    }

    public function testThrowsOnCloseBeforeWritingComplete(): void
    {
        $this->expectException(ClosedException::class);

        Task::await(Task::async(function () {
            [$a] = $this->getStreamPair(4096);

            $message = \str_repeat(".", 8192 /* default chunk size */);

            $promise = Task::async(function () use ($a, $message) {
                return $a->write($message);
            });

            $a->close();

            Task::await($promise);
        }));
    }

    public function testThrowsOnStreamNotWritable(): void
    {
        $this->expectException(StreamException::class);

        Task::await(Task::async(function () {
            [$a] = $this->getStreamPair();

            $a->close();
            $a->end(".");
        }));
    }

    public function testThrowsOnReferencingClosedStream(): void
    {
        $this->expectException(\Error::class);

        Task::await(Task::async(function () {
            [, $b] = $this->getStreamPair();

            $b->close();

            $b->reference();
        }));
    }

    public function testThrowsOnUnreferencingClosedStream(): void
    {
        $this->expectException(\Error::class);

        Task::await(Task::async(function () {
            [, $b] = $this->getStreamPair();

            $b->close();

            $b->unreference();
        }));
    }

    public function testThrowsOnPendingRead(): void
    {
        $this->expectException(PendingReadError::class);

        Task::await(Task::async(function () {
            [, $b] = $this->getStreamPair();

            $readOp = Task::async([$b, 'read']);
            $b->read();
            Task::await($readOp);
        }));
    }

    public function testResolveSuccessOnClosedStream(): void
    {
        Task::await(Task::async(function () {
            [, $b] = $this->getStreamPair();

            $b->close();

            $this->assertNull($b->read());
        }));
    }

    public function testChunkedPayload(): void
    {
        Task::await(Task::async(function () {
            [$a, $b] = $this->getStreamPair(4096);

            $message = \str_repeat(".", 8192 /* default chunk size */);

            // TODO: Rethrow
            Task::async([$a, 'end'], [$message]);

            $received = "";
            while (null !== $chunk = $b->read()) {
                $received .= $chunk;
            }

            $this->assertSame($message, $received);
        }));
    }

    public function testEmptyPayload(): void
    {
        Task::await(Task::async(function () {
            [$a, $b] = $this->getStreamPair(4096);

            $message = "";

            // TODO: Rethrow
            Task::async([$a, 'end'], [$message]);

            $received = "";
            while (null !== $chunk = $b->read()) {
                $received .= $chunk;
            }

            $this->assertSame($message, $received);
        }));
    }

    public function testCloseStreamAfterEndPayload(): void
    {
        Task::await(Task::async(function () {
            [$a, $b] = $this->getStreamPair();

            $message = \str_repeat(".", 8192 /* default chunk size */);

            // TODO: Rethrow
            Task::async([$a, 'end'], [$message]);

            $received = "";
            while (null !== $chunk = $b->read()) {
                $received .= $chunk;
            }

            $this->assertSame($message, $received);
        }));
    }
}
