<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

final class IntentRouter
{
    /**
     * @param  list<string>  $modules
     */
    public function route(array $modules, Interpretation $interpretation): RouteDecision
    {
        $cafe = in_array('cafe', $modules, true);
        $tiffin = in_array('tiffin', $modules, true);

        if ($interpretation->kind === 'tiffin_change') {
            if ($tiffin) {
                return new RouteDecision('tiffin');
            }

            return new RouteDecision(
                'handoff',
                'This number takes café pickup orders. It does not start or change a tiffin subscription.',
                'unsupported',
            );
        }

        if ($interpretation->kind === 'pickup') {
            if ($cafe) {
                return new RouteDecision('pickup');
            }

            return new RouteDecision(
                'handoff',
                'This number manages tiffin meals, not café pickup orders.',
                'unsupported',
            );
        }

        if ($interpretation->kind === 'menu') {
            if ($cafe) {
                return new RouteDecision('menu');
            }
            if ($tiffin) {
                return new RouteDecision('tiffin_menu');
            }

            return new RouteDecision('handoff', 'A person will look at this message.', 'unclear');
        }

        if ($interpretation->kind === 'ambiguous') {
            if ($cafe && $tiffin) {
                return new RouteDecision('clarify', (string) config('pilot.clarify_both_modules'));
            }

            if ($cafe) {
                return new RouteDecision('menu');
            }

            return new RouteDecision('clarify', 'Do you want to skip, pause, or change a tiffin meal?');
        }

        return new RouteDecision('handoff', 'A person will look at this message.', 'unclear');
    }
}
