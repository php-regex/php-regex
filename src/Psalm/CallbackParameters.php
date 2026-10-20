<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Psalm;

use Psalm\Plugin\EventHandler\Event\FunctionParamsProviderEvent;
use Psalm\Plugin\EventHandler\FunctionParamsProviderInterface;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Union;

/**
 * The parameters of preg_replace_callback() for one call: the stub's, the
 * callback taking what preg_match() writes on a match of the call's pattern
 * instead of string[]. Psalm types an untyped closure parameter from it,
 * and narrows an array one.
 *
 * Psalm reads these parameters for every call, in place of the stub's: a
 * call the plugin cannot read gets the stub's own.
 *
 * @internal
 */
final class CallbackParameters implements FunctionParamsProviderInterface
{
    public static function getFunctionIds(): array
    {
        return ['preg_replace_callback'];
    }

    public static function getFunctionParams(FunctionParamsProviderEvent $event): ?array
    {
        $source = $event->getStatementsSource();
        $context = $event->getContext();
        $parameters = PsalmInternals::parameters($source, 'preg_replace_callback');
        if (null === $parameters) {
            return null; // Never on the Psalm releases the plugin reads: the stub declares the function.
        }

        $type = null === $context ? null : PregCallAnalyzer::callbackMatchesType($event->getCallArgs(), $source, $context);
        if (null === $type) {
            return $parameters;
        }

        $callback = new Union([new TCallable('callable', [new FunctionLikeParameter('matches', false, $type, $type, null, null, false)], Type::getString())]);

        return array_map(static fn (FunctionLikeParameter $parameter): FunctionLikeParameter => 'callback' === $parameter->name ? $parameter->setType($callback) : $parameter, $parameters);
    }
}
