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

namespace Cerberus\Plugins;

use Cerberus\Plugin;
use Cerberus\Mircryption;

/**
 * Class PluginCrypt
 * @package Cerberus
 * @author Stefan Hüsges
 * @link http://www.mpcx.net/projekte/cerberus/ Project Homepage
 * @link https://github.com/tronsha/cerberus Project on GitHub
 * @link http://tools.ietf.org/html/rfc2812 Internet Relay Chat: Client Protocol
 * @license http://www.gnu.org/licenses/gpl-3.0 GNU General Public License
 */
class PluginCrypt extends Plugin
{
    private $cryptkey = [];
    private $mircryption = null;

    /**
     *
     */
    protected function init()
    {
        if (extension_loaded('mcrypt')) {
            $this->mircryption = new Mircryption;
            $this->irc->addEvent('onPrivmsg', $this, 10);
        } else {
            $this->irc->sysinfo('The mcrypt extension is not available.');
        }
    }

    /**
     * @param array $data
     * @return bool
     */
    public function onLoad($data)
    {
        $returnValue = parent::onLoad($data);
        if ($data !== null) {
            $this->irc->getAction()->notice($data['nick'], 'New Command: !cryptkey [#channel] [key]');
        }
        return $returnValue;
    }

    /**
     * @param array $data
     */
    public function onPrivmsg(&$data)
    {
        $splitText = explode(' ', $data['text']);
        $command = array_shift($splitText);
        if ($command == '+OK') {
            $key = empty($this->cryptkey[$data['channel']]) ? '123456' : $this->cryptkey[$data['channel']];
            $data['text'] = $this->decodeMircryption(array_shift($splitText), $key);
        } elseif (strtolower($command) == '!cryptkey' && $this->irc->isAdmin($data['nick'], $data['host'])) {
            $channel = array_shift($splitText);
            $key = array_shift($splitText);
            $this->cryptkey[$channel] = $key;
        }
    }

    /**
     * @param string $text
     * @param string $key
     * @return string
     */
    protected function decodeMircryption($text, $key = '123456')
    {
        return $this->mircryption->decode($text, $key);
    }

    /**
     * @param string $text
     * @param string $key
     * @return string
     */
    protected function encodeMircryption($text, $key = '123456')
    {
        return '+OK ' . $this->mircryption->encode($text, $key);
    }
}
