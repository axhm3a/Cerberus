<?php

/*   Cerberus IRCBot
 *   Copyright (C) 2008 - 2015 Stefan Hüsges
 *
 *   This program is free software; you can redistribute it and/or modify it
 *   under the terms of the GNU General Public License as published by the Free
 *   Software Foundation; either version 3 of the License, or (at your option)
 *   any later version.
 *
 *   This program is distributed in the hope that it will be useful, but
 *   WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 *   or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License
 *   for more details.
 *
 *   You should have received a copy of the GNU General Public License along
 *   with this program; if not, see <http://www.gnu.org/licenses/>.
 */

namespace Cerberus;


use Cerberus\Formatter\FormatterFactory;

class FormatterTest extends \PHPUnit_Framework_TestCase
{
    protected $formatter;

    protected function setUp()
    {
        $this->formatter = FormatterFactory::console();
    }

    protected function tearDown()
    {
        unset($this->formatter);
    }

    public function testBold()
    {
        $this->assertEquals("\033[1mfoo\033[22m", $this->formatter->bold("\x02foo\x02"));
        $this->assertEquals("\033[1mfoo\033[22m", $this->formatter->bold("\x02foo"));
    }
}