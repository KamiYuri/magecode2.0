<?php

declare(strict_types=1);

namespace App\Messaging;

interface JobPublisher
{
    /**
     * Hand a message to the broker. Returns once the broker has confirmed it;
     * anything short of that is a PublishFailed, never a silent success.
     *
     * @throws PublishFailed
     */
    public function publish(QueueMessage $message): void;
}
