<?php

namespace MindKitchen\Yii2Sentry\yii2;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\Severity;
use Sentry\State\Scope;
use Throwable;
use Yii;
use yii\di\Instance;
use yii\helpers\ArrayHelper;
use yii\log\Logger;
use yii\log\Target;
use yii\web\Request;
use yii\web\User;

/**
 * SentryTarget records log messages in a Sentry.
 *
 * @see https://sentry.io
 */
class ProfileTarget extends Target
{
    public function init()
    {
        parent::init();

        Instance::ensure(Component::ID, Component::class);
    }

    /**
     * @inheritdoc
     */
    public function export()
    {
        foreach ($this->messages as $message) {

            [$text, $level, $category, $timestamp] = $message;

            if (!in_array($level, [
                Logger::LEVEL_PROFILE,
                Logger::LEVEL_PROFILE_BEGIN,
                Logger::LEVEL_PROFILE_END
            ])) continue;

            if ($level == Logger::LEVEL_PROFILE_BEGIN) {
                [$span, $parent] = Yii::$app->sentry->addSpan($text, $timestamp);
            }

            elseif ($level == Logger::LEVEL_PROFILE_END) {
                Yii::$app->sentry->finishSpan($span, null, $timestamp);
            }

        }
    }


}
