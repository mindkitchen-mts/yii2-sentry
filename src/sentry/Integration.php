<?php

namespace MindKitchen\Yii2Sentry\sentry;

use Sentry\Event;
use Sentry\Integration\IntegrationInterface;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use yii\base\BaseObject;

class Integration extends BaseObject implements IntegrationInterface
{
    /**
     * List of HTTP methods for whom the request body must be passed to the Sentry
     *
     * @var string[]
     */
    public $httpMethodsWithRequestBody = ['POST', 'PUT', 'PATCH'];

    /**
     * {@inheritdoc}
     */
    public function setupOnce(): void
    {
        Scope::addGlobalEventProcessor(function (Event $event): Event {
            $self = SentrySdk::getCurrentHub()->getIntegration(self::class);

            if (!$self instanceof self) {
                return $event;
            }
            $this->applyToEvent($event);

            return $event;
        });
    }

    protected function applyToEvent(Event $event): void
    {
        $request = \Yii::$app->getRequest();
        
        // Skip if the current request is made via console.
        if ($request->isConsoleRequest) {
            return;
        }        
        
        $requestMethod = $request->getMethod();

        $requestData = [
            'url' => $request->getUrl(),
            'method' => $requestMethod,
            'query_string' => $request->getQueryString(),
        ];

        // Process headers, cookies, etc. Done the same way as in RequestIntegration, but using Yii's stuff.
        /** @see \Sentry\Integration\RequestIntegration */
        $headers = $request->getHeaders();
        if ($headers->has('REMOTE_ADDR')) {
            $requestData['env']['REMOTE_ADDR'] = $headers->get('REMOTE_ADDR');
        }
        $requestData['cookies'] = $request->getCookies();
        $requestData['headers'] = $headers->toArray();

        // Process request body
        if (\in_array($requestMethod, $this->httpMethodsWithRequestBody, true)) {
            $rawBody = $request->getRawBody();
            if ($rawBody !== '') {
                $requestData['data'] = $rawBody;
            }
        }

        // Set!
        $event->setRequest($requestData);
    }

}
