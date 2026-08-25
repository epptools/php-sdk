<?php

declare(strict_types=1);

namespace EppTools\Command;

use EppTools\Client;
use EppTools\Exception\CommandException;
use EppTools\Response;
use EppTools\ResultCode;

/**
 * The service message queue (RFC 5730 §2.9.2.3). Reached via Client::poll().
 *
 * Use Response::messageId() / messageCount() to read the queue head, then ack() it.
 */
final class Poll
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /** Request the next service message (1301 with a message, 1300 when empty). */
    public function request(): Response
    {
        $frame = $this->client->frame();
        $frame->verb('poll')->setAttribute('op', 'req');

        return $this->client->request($frame);
    }

    /**
     * Acknowledge a message, which DELETES it at the registry. There is no way to get it back.
     */
    public function ack(string $messageId): Response
    {
        $frame = $this->client->frame();
        $poll = $frame->verb('poll');
        $poll->setAttribute('op', 'ack');
        $poll->setAttribute('msgID', $messageId);

        return $this->client->request($frame);
    }

    /**
     * Read the queue to the end, handing each notice to your callback.
     *
     *     $client->poll()->drain(function (Response $notice): void {
     *         $this->store($notice->queueMessage(), $notice->pendingActionData());
     *     });
     *
     * The ORDER is the point, and it is the thing this loop exists to get right: the message is
     * acknowledged only AFTER your callback returns. An ack deletes the notice at the registry
     * permanently, so a loop that acks first and processes second loses every notice whose
     * processing fails — a transfer request, a delete notification, the outcome of a pending
     * create — with nothing left to retry from and no record that anything was lost.
     *
     * So: if your callback throws, the notice is NOT acked. It stays at the head of the queue and
     * the exception reaches you. Fix the cause and drain again; nothing was lost. That also means a
     * callback which always throws will see the same notice every time — deliberately, because the
     * alternative is discarding it.
     *
     * Delivery is AT LEAST ONCE. If the acknowledgement itself fails — the connection drops between
     * your callback returning and the ack landing — the notice is still in the queue and the next
     * drain hands it to you again. Make the callback idempotent and use messageId() as the
     * de-duplication key; it is the registry's own identifier for that notice.
     *
     * Returns the number of notices your callback processed successfully. Stops at the first empty
     * queue (1300); any other reply that carries no notice is raised rather than read as "empty".
     *
     * @param callable(Response): void $handler
     * @param int $limit stop after this many notices; 0 means "until the queue is empty". A queue
     *        that fills faster than you drain it would otherwise keep this call running forever.
     */
    public function drain(callable $handler, int $limit = 0): int
    {
        $processed = 0;
        while ($limit === 0 || $processed < $limit) {
            $notice = $this->request();
            // Only 1300 means the queue is empty. Inferring emptiness from "no <msgQ>" would make a
            // refusal — the session closed, the account suspended — look exactly like a drained
            // queue, and the loop would report success while nothing had been read. With
            // throwOnFailure(false) the refusal reaches here instead of being raised, so it is
            // caught explicitly rather than falling through.
            if ($notice->code() === ResultCode::SUCCESS_NO_MESSAGES) {
                break;
            }
            $messageId = $notice->messageId();
            if ($messageId === null) {
                throw new CommandException(
                    $notice->code(),
                    sprintf(
                        'poll returned neither a message nor an empty queue (EPP %d: %s)',
                        $notice->code(),
                        $notice->message() ?? 'no message',
                    ),
                    $notice,
                );
            }
            $handler($notice);
            $this->ack($messageId);
            $processed++;
        }

        return $processed;
    }
}

