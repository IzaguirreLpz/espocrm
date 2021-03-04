<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2021 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace tests\unit\Espo\Core\Fields\Address;

use Espo\Core\{
    Fields\EmailAddress\EmailAddress,
};

use RuntimeException;

class EmailAddressTest extends \PHPUnit\Framework\TestCase
{
    public function testInvalid()
    {
        $this->expectException(RuntimeException::class);

        EmailAddress::fromAddress('one');
    }

    public function testCloneInvalid()
    {
        $address = EmailAddress::fromAddress('test@test.com')->invalid();

        $this->assertEquals('test@test.com', $address->getAddress());

        $this->assertTrue($address->isInvalid());
        $this->assertFalse($address->isOptedOut());
    }

    public function testCloneOptedOut()
    {
        $address = EmailAddress::fromAddress('test@test.com')->optedOut();

        $this->assertFalse($address->isInvalid());
        $this->assertTrue($address->isOptedOut());
    }

    public function testCloneNotOptedOut()
    {
        $address = EmailAddress::fromAddress('test@test.com')
            ->optedOut()
            ->notOptedOut()
            ->invalid()
            ->notInvalid();

        $this->assertFalse($address->isInvalid());
        $this->assertFalse($address->isOptedOut());
    }
}
