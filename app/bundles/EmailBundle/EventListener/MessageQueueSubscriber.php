<?php
/**
 * @copyright   2016 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\EmailBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Entity\MessageQueue;
use Mautic\CoreBundle\Event\MessageQueueBatchProcessEvent;
use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\EmailBundle\Model\EmailModel;

/**
 * Class CalendarSubscriber.
 */
class MessageQueueSubscriber extends CommonSubscriber
{
    /**
     * @var EmailModel
     */
    protected $emailModel;

    /**
     * MessageQueueSubscriber constructor.
     *
     * @param EmailModel $emailModel
     */
    public function __construct(EmailModel $emailModel)
    {
        $this->emailModel = $emailModel;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            CoreEvents::PROCESS_MESSAGE_QUEUE_BATCH => ['onProcessMessageQueueBatch', 0],
        ];
    }

    /**
     * Sends campaign emails.
     *
     * @param MessageQueueBatchProcessEvent $event
     */
    public function onProcessMessageQueueBatch(MessageQueueBatchProcessEvent $event)
    {
        if (!$event->checkContext('email')) {
            return;
        }

        $messages = $event->getMessages();
        $emailId  = $event->getChannelId();
        $email    = $this->emailModel->getEntity($emailId);

        $sendTo            = [];
        $messagesByContact = [];
        $options           = [
            'email_type' => 'marketing',
        ];

        /** @var MessageQueue $message */
        foreach ($messages as $id => $message) {
            $contact = $message->getLead()->getProfileFields();
            if (empty($contact['email'])) {
                // No email so just let this slide
                die('nope');
                $message->setProcessed();
                $message->setSuccess();
            }

            $sendTo[$contact['id']]            = $contact;
            $messagesByContact[$contact['id']] = $message;
        }

        if (count($sendTo)) {
            $options['resend_message_queue'] = $messagesByContact;
            $errors                          = $this->emailModel->sendEmail($email, $sendTo, $options);

            // Let's see who was successful
            foreach ($messagesByContact as $contactId => $message) {
                // If the message is processed, it was rescheduled by sendEmail
                if (!$message->isProcessed()) {
                    $message->setProcessed();
                    if (empty($errors[$contactId])) {
                        $message->setSuccess();
                    }
                }
            }
        }

        $event->stopPropagation();
    }
}
