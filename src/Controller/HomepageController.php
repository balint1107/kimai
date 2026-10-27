<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Homepage controller is a redirect controller with user specific logic.
 */
#[Route(path: '/homepage')]
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class HomepageController extends AbstractController
{
    public const DEFAULT_ROUTE = 'shift_calendar';

    /** Returns the current frontend for $request's active locale; legacy dashboard preferences cannot escape the application. */
    #[Route(path: '', defaults: [], name: 'homepage', methods: ['GET'])]
    public function homepage(Request $request): Response
    {
        return $this->redirectToRoute('shift_calendar', ['_locale' => $request->getLocale()]);
    }
}
