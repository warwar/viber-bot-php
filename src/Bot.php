<?php

namespace Viber;

use Closure;
use Viber\Client;
use Viber\Bot\Manager;
use Viber\Api\Event;
use Viber\Api\Signature;
use Viber\Api\Event\Factory;
use Viber\Api\Entity;

/**
 * Build bot with viber client
 *
 * @author Novikov Bogdan <hcbogdan@gmail.com>
 */
class Bot
{
    /**
     * Api client
     *
     * @var \Viber\Client
     */
    protected $client;

    /**
     * Event managers collection
     *
     * @var array
     */
    protected $managers = [];

    /**
     * Init client
     *
     * Required options (one of two):
     * token  string
     * client \Viber\Client
     *
     * @throws \RuntimeException
     * @param array $options
     */
    public function __construct(array $options)
    {
        if (isset($options['token'])) {
            $this->client = new Client($options);
        } elseif (isset($options['client']) && $options['client'] instanceof Client) {
            $this->client = $options['client'];
        } else {
            throw new \RuntimeException('Specify "client" or "token" parameter');
        }
    }

    /**
     * Register event handler callback
     *
     * @param \Closure $checker checker function
     * @param \Closure $handler handler function
     *
     * @return \Viber\Bot
     */
    public function on(\Closure $checker, \Closure $handler)
    {
        $this->managers[] = new Manager($checker, $handler);
        return $this;
    }

    /**
     * Register text message handler by PCRE
     *
     * @param  string $regexp valid regular expression
     * @param  Closure $handler event handler
     * @return \Viber\Bot
     */
    public function onText($regexp, \Closure $handler)
    {
        $this->managers[] = new Manager(function (Event $event) use ($regexp) {
            return (
                $event instanceof \Viber\Api\Event\Message
                && preg_match($regexp, $event->getMessage()->getText())
            );
        }, $handler);
        return $this;
    }

    /**
     * Register subscrive event handler
     *
     * @param  Closure $handler valid function
     * @return \Viber\Bot
     */
    public function onSubscribe(\Closure $handler)
    {
        $this->managers[] = new Manager(function (Event $event) {
            return ($event instanceof \Viber\Api\Event\Subscribed);
        }, $handler);
        return $this;
    }

    /**
     * Register conversation event handler
     *
     * @param  Closure $handler valid function
     * @return \Viber\Bot
     */
    public function onConversation(\Closure $handler)
    {
        $this->managers[] = new Manager(function (Event $event) {
            return ($event instanceof \Viber\Api\Event\Conversation);
        }, $handler);
        return $this;
    }

    /**
     * Start bot process
     *
     * @throws \RuntimeException
     * @param \Viber\Api\Event $event start bot with some event
     * @return \Viber\Bot
     */
    public function run($event = null)
    {
        if (is_null($event)) {
            // check body
            $eventBody = $this->getInputBody();
            if (!Signature::isValid(
                $this->getSignHeaderValue(),
                $eventBody,
                $this->getClient()->getToken()
            )
            ) {
                throw new \RuntimeException('Invalid signature header', 2);
            }
            // check json
            $eventBody = json_decode($eventBody, true);
            if (json_last_error() || empty($eventBody) || !is_array($eventBody)) {
                throw new \RuntimeException('Invalid json request', 3);
            }
            // make event from json
            $event = Factory::makeFromApi($eventBody);
        } elseif (!$event instanceof Event) {
            throw new \RuntimeException('Event must be instance of \Viber\Api\Event', 4);
        }
        // main bot loop
        foreach ($this->managers as $manager) {
            if ($manager->isMatch($event)) {
                $returnValue = $manager->runHandler($event);
                if ($returnValue && $returnValue instanceof Entity) { // reply with entity
                    $this->outputEntity($returnValue);
                }
                break;
            }
        }
        return $this;
    }

    /**
     * Get bot input stream
     *
     * @return string
     */
    public function getInputBody()
    {
        return file_get_contents('php://input');
    }

    /**
     * Get signature header
     *
     * @throws \RuntimeException
     * @return string
     */
    public function getSignHeaderValue()
    {
        if (isset($_GET['sig']) && !empty($_GET['sig'])) {
            return $_GET['sig'];
        }
        $headerName = 'HTTP_X_VIBER_CONTENT_SIGNATURE';
        if (!isset($_SERVER[$headerName])) {
            throw new \RuntimeException($headerName . ' header not found', 1);
        }
        return $_SERVER[$headerName];
    }

    /**
     * Get current bot client
     *
     * @return |Viber\Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Response with entity
     *
     * @param  Entity $entity
     * @return void
     */
    public function outputEntity(Entity $entity)
    {
        header('Content-Type: application/json');
        echo json_encode($entity->toApiArray());
    }
}
