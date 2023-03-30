<?php

namespace MindKitchen\Yii2Sentry\yii2;

use MindKitchen\Yii2Sentry\sentry\Integration;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\ExceptionListenerIntegration;
use Sentry\Integration\FatalErrorListenerIntegration;
use Sentry\Integration\IntegrationInterface;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\Span;
use Sentry\Tracing\TraceId;
use yii\base\ActionEvent;
use yii\base\BootstrapInterface;
use yii\base\Controller;
use yii\base\Event;
use yii\base\InlineAction;
use yii\web\User;
use yii\web\UserEvent;

class Component extends \yii\base\Component implements BootstrapInterface
{
    const ID = 'sentry';

    /**
     * @var string Sentry client key.
     */
    public $dsn;
    /**
     * @var array
     */
    public $sentrySettings = [];
    /**
     * @var array
     */
    public $integrations = [
        Integration::class,
    ];
    /**
     * @var string
     */
    public $appBasePath = '@app';

    /**
     * @var string
     */
    public $serviceTag;

    /**
     * @var string
     */
    public $serviceTagName = 'yii';

    /**
     * @var HubInterface
     */
    protected $hub;

    const PARENT_SAMPLED = "parentSampled";
    const PARENT_ID = "parentId";
    const TRACE_ID = "traceId";

    public function init()
    {
        parent::init();

        $basePath = \Yii::getAlias($this->appBasePath);
        $options = array_merge([
            'dsn' => $this->dsn,
        ], $this->sentrySettings);

        $builder = ClientBuilder::create($options);

        $clientOptions = $builder->getOptions();
        $clientOptions->setIntegrations(static function (array $integrations) {
            $integrations = array_filter($integrations, static function (IntegrationInterface $integration): bool {
                if ($integration instanceof ErrorListenerIntegration) {
                    return false;
                }
                if ($integration instanceof ExceptionListenerIntegration) {
                    return false;
                }
                if ($integration instanceof FatalErrorListenerIntegration) {
                    return false;
                }
                return true;
            });

            $integrations[] = new Integration();

            return $integrations;
        });

        SentrySdk::init()->bindClient($builder->getClient());
    }

    /**
     * @inheritDoc
     */
    public function bootstrap($app)
    {
        if ($this->serviceTag) {
            $this->addTag($this->serviceTagName, $this->serviceTag);
        }

        Event::on(User::class, User::EVENT_AFTER_LOGIN, function (UserEvent $event) {
            SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) use ($event): void {
                $scope->setUser([
                    'id' => $event->identity->getId(),
                ]);
            });
        });

        Event::on(Controller::class, Controller::EVENT_AFTER_ACTION, function (ActionEvent $event) use ($app) {
            $span = \Sentry\SentrySdk::getCurrentHub()->getSpan();
            $transaction = \Sentry\SentrySdk::getCurrentHub()->getTransaction();

            // Finish the span
            $span->finish();

            // Set the current span back to the transaction since we just finished the previous spanå
            \Sentry\SentrySdk::getCurrentHub()->setSpan($transaction);

            if (\Yii::$app instanceof \yii\web\Application) {
                \Yii::$app->session->set(self::PARENT_SAMPLED, $transaction->getSampled());
                \Yii::$app->session->set(self::PARENT_ID, $transaction->getSpanId());
            }

            // Finish the transaction
            $transaction->finish();
        });


        Event::on(Controller::class, Controller::EVENT_BEFORE_ACTION, function (ActionEvent $event) use ($app) {
            $route = $event->action->getUniqueId();
            $metadata = [];
            // Retrieve action's function
            if ($app->requestedAction instanceof InlineAction) {
                $metadata['action'] = get_class($app->requestedAction->controller) . '::' . $app->requestedAction->actionMethod . '()';
            } else {
                $metadata['action'] = get_class($app->requestedAction) . '::run()';
            }

            // Set breadcrumb
            SentrySdk::getCurrentHub()->addBreadcrumb(new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_NAVIGATION,
                'route',
                $route,
                $metadata
            ));

            // Set "route" tag
            SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) use ($route): void {
                $scope->setTag('route', $route);
            });

            // Setup context for the full transaction
            $transactionContext = new \Sentry\Tracing\TransactionContext();
            $transactionContext->setName($event->action->getUniqueId());
            $transactionContext->setOp('http.request');

            if (\Yii::$app instanceof \yii\web\Application) {
                if (\Yii::$app->session->has(self::PARENT_SAMPLED)) {
                    $transactionContext->setParentSampled(\Yii::$app->session->get(self::PARENT_SAMPLED));
                    $transactionContext->setParentSpanId(\Yii::$app->session->get(self::PARENT_ID));
                }
                if (\Yii::$app->session->has(self::PARENT_ID)) {
                    $transactionContext->setParentSpanId(\Yii::$app->session->get(self::PARENT_ID));
                }
                if (\Yii::$app->session->has(self::TRACE_ID)) {
                    $traceId = new TraceId(\Yii::$app->session->get(self::TRACE_ID));
                } else {
                    $traceId = TraceId::generate();
                    \Yii::$app->session->set(self::TRACE_ID, strval($traceId));
                }
                $transactionContext->setTraceId($traceId);
            }

            // Start the transaction
            $transaction = \Sentry\startTransaction($transactionContext);

            // Set the current transaction as the current span so we can retrieve it later
            \Sentry\SentrySdk::getCurrentHub()->setSpan($transaction);

            // Setup the context for the expensive operation span
            $spanContext = new \Sentry\Tracing\SpanContext();
            $spanContext->setOp('request_processing');

            // Start the span
            $span = $transaction->startChild($spanContext);

            // Set the current span to the span we just started
            \Sentry\SentrySdk::getCurrentHub()->setSpan($span);
        });
    }

    public function addTag($tag, $value) {
        // Set "route" tag
        \Sentry\SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) use ($tag, $value): void {
            $scope->setTag($tag, $value);
        });
    }

    public function addSpan(string $operationName, $timestamp = null) {
        $parent = \Sentry\SentrySdk::getCurrentHub()->getSpan();
        $span = null;

        // Check if we have a parent span (this is the case if we started a transaction earlier)
        if ($parent !== null) {
            $context = new \Sentry\Tracing\SpanContext();
            $context->setOp($operationName);
            $span = $parent->startChild($context);
            if ($timestamp) {
                $span->setStartTimestamp($timestamp);
            }

            // Set the current span to the span we just started
            \Sentry\SentrySdk::getCurrentHub()->setSpan($span);
        }

        return [$span, $parent];
    }

    public function finishSpan(Span $span = null, Span $parent = null, $timestamp = null) {
        // We only have a span if we started a span earlier
        if (!$span) {
            $span = \Sentry\SentrySdk::getCurrentHub()->getSpan();
        }
        if ($span) {
            $span->finish($timestamp);
            if ($parent) {
                // Restore the current span back to the parent span
                \Sentry\SentrySdk::getCurrentHub()->setSpan($parent);
            }
        }
    }
}
