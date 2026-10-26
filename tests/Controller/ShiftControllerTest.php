<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\Group;

/** Checks that the accounting interface retains native session authentication and CSRF requirements. */
#[Group('integration')]
class ShiftControllerTest extends AbstractControllerBaseTestCase
{
    /** Rejects anonymous access to calendar and accounting data. */
    public function testIsSecure(): void
    {
        $this->assertUrlIsSecured('/shifts');
    }

    /** Protects the data endpoint independently of the HTML calendar. */
    public function testDataIsSecure(): void
    {
        $this->assertUrlIsSecured('/shifts/data');
    }

    /** Displays the shell for a logged-in owner and rejects a JSON mutation without a CSRF token. */
    public function testCalendarAndCsrf(): void
    {
        $client = $this->getClientForAuthenticatedUser();
        $this->request($client, '/shifts');
        self::assertTrue($client->getResponse()->isSuccessful());
        self::assertCount(1, $client->getCrawler()->filter('#shift-calendar'));
        $client->request('POST', '/en/shifts/save', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }
}
