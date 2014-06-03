<?php

/*   Cerberus IRCBot
 *   Copyright (C) 2008 - 2014 Stefan Hüsges
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

use Cerberus\Plugins\PluginAuth;

/**
 * Class Irc
 * @package Cerberus
 * @author Stefan Hüsges
 * @link http://www.mpcx.net/cerberus/ Project Homepage
 * @link https://github.com/tronsha/Cerberus Project on GitHub
 * @link http://tools.ietf.org/html/rfc2812 Internet Relay Chat: Client Protocol
 * @license http://www.gnu.org/licenses/gpl-3.0 GNU General Public License
 */

class Irc extends Cerberus
{
    protected $server = array();
    protected $bot = array();
    protected $db = array();
    /**
     * @var string
     */
    protected $dbms;
    /**
     * @var resource
     */
    protected $fp = false;
    protected $init = false;
    protected $run;
    protected $lastping;
    protected $nowrite;
    protected $var = array();
    protected $time = array();
    protected $version = array();
    protected $config = array();
    protected $reconnect = array();
    protected $loaded = array();
    protected $pluginevents = array();
    protected $auth = null;

    public function __construct($config = null)
    {
        $this->time['script_start'] = $this->getMicrotime();
        $this->bot['pid'] = getmypid();
        $this->bot['nick'] = null;
        $this->server['network'] = null;
        $this->server['password'] = null;
        $this->config['db'] = array();
        $this->config['info'] = array('name' => 'Cerberus');
        $this->reconnect['channel'] = array();
        $this->loaded['classes'] = array();
        $this->config['dbms'] = array('mysql' => 'MySQL', 'pg' => 'PostgreSQL', 'sqlite' => 'SQLite');
        $this->config['autorejoin'] = false;
        $this->config['ctcp'] = false;
        $this->config['logfiledirectory'] = $this->getPath() . '/log/';
        $this->config['logfile']['error'] = true;
        $this->config['logfile']['socket'] = false;
        $this->config['logfile']['sql'] = false;
        $this->config['dailylogfile'] = true;

        if (is_array($config)) {
            if (!empty($config['bot']['nick'])) {
                $this->setNick($config['bot']['nick']);
            }
            if (!empty($config['irc']['network'])) {
                $this->setNetwork($config['irc']['network']);
            }
            if (!empty($config['irc']['password'])) {
                $this->setPassword($config['irc']['password']);
            }
            if (isset($config['db'])) {
                $this->setDB($config['bot']['dbms'], $config['db']);
            }
            if (!empty($config['info']['name'])) {
                $this->config['info']['name'] = $config['info']['name'];
            }
            if (!empty($config['info']['homepage'])) {
                $this->config['info']['homepage'] = $config['info']['homepage'];
            }
            if (!empty($config['info']['version'])) {
                $this->version['bot'] = $config['info']['version'];
            }
            if (isset($config['bot']['autorejoin'])) {
                $this->config['autorejoin'] = $config['bot']['autorejoin'] == 1 ? true : false;
            }
            if (isset($config['bot']['ctcp'])) {
                $this->config['ctcp'] = $config['bot']['ctcp'] == 1 ? true : false;
            }
            if (isset($config['log']['directory']) && is_dir($config['log']['directory'])) {
                $this->config['logfiledirectory'] = $config['log']['directory'];
            }
            if (isset($config['log']['error'])) {
                $this->config['logfile']['error'] = $config['log']['error'] == 1 ? true : false;
            }
            if (isset($config['log']['socket'])) {
                $this->config['logfile']['socket'] = $config['log']['socket'] == 1 ? true : false;
            }
            if (isset($config['log']['sql'])) {
                $this->config['logfile']['sql'] = $config['log']['sql'] == 1 ? true : false;
            }
            if (isset($config['log']['dailylogfile'])) {
                $this->config['dailylogfile'] = $config['log']['dailylogfile'] == 1 ? true : false;
            }
            if (isset($config['plugins']['autoload'])) {
                $this->config['plugins']['autoload'] = explode(',', $config['plugins']['autoload']);
            }
        }
    }

    public function __destruct()
    {
        if ($this->init === true) {
            if ($this->fp !== false) {
                fclose($this->fp);
            }
            $this->clear();
            $this->sql_query('UPDATE `bot` SET `stop` = NOW() WHERE `id` = "' . $this->bot['id'] . '"');
            $this->db->close();
        }
        printf(
            PHP_EOL . PHP_EOL . "Execute time: %.5fs" . PHP_EOL,
            $this->getMicrotime() - $this->time['script_start']
        );
    }

    /**
     * @param $network
     * @return $this
     */
    public function setNetwork($network)
    {
        $this->server['network'] = $network;
        return $this;
    }

    /**
     * @param $password
     * @return $this
     */
    public function setPassword($password)
    {
        $this->server['password'] = $password;
        return $this;
    }

    /**
     * @param $dbms
     * @param $config
     * @return $this
     */
    public function setDB($dbms, $config)
    {
        $this->dbms = strtolower($dbms);
        $this->config['db'] = $config;
        $this->db = new Db($this->dbms, $this->config['db']);
        return $this;
    }

    /**
     * @param null $nick
     * @return $this
     */
    public function setNick($nick = null)
    {
        if ($nick === null) {
            $nick = $this->randomNick();
        }
        $this->bot['nick'] = $nick;
        $this->var['me'] = $nick;
        if ($this->init === true) {
            $this->sql_query(
                'UPDATE `bot` SET `nick` = "' . $this->db->escape_string(
                    $this->bot['nick']
                ) . '" WHERE `id` = "' . $this->bot['id'] . '"'
            );
        }
        return $this;
    }

    /**
     * @param $text
     */
    public function sysinfo($text)
    {
        echo '**** ' . $text . ' ****' . PHP_EOL;
    }

    /**
     * @return bool
     */
    public function init()
    {
        if (isset($this->server['network']) === false || $this->db === null) {
            return false;
        }
        $this->dbConnect();
        $this->sql_query(
            'INSERT INTO `bot` SET `pid` = "' . $this->bot['pid'] . '", `start` = NOW(), `nick` = "' . $this->db->escape_string(
                $this->bot['nick']
            ) . '"'
        );
        $this->bot['id'] = $this->db->insert_id();
        if (isset($this->version['bot']) === false) {
            $this->version['php'] = phpversion();
            $this->version['os'] = php_uname('s') . ' ' . php_uname('r');
            $this->version['bot'] = 'PHP ' . $this->version['php'] . ' - ' . $this->version['os'];
            if ($this->dbms == 'mysql' || $this->dbms == 'pg') {
                $this->version['sql'] = $this->db->result($this->sql_query('SELECT VERSION()'), 0, 0);
                $this->version['bot'] .= ' - ' . $this->config['dbms'][$this->dbms] . ' ' . $this->version['sql'];
            }
        }
        if (is_array($this->config['plugins']['autoload']) === true) {
            $this->autoloadPlugins();
        }
        $this->init = true;
        return true;
    }

    /**
     * @return string
     */
    public static function randomNick()
    {
        $konsonant = 'bcdfghjklmnpqrstvwxyz';
        $vokal = 'aeiou';
        $nick = '';
        for ($i = 0; $i < 3; $i++) {
            $nick .= $konsonant{rand(0, 20)} . $vokal{rand(0, 4)};
        }
        return ucfirst($nick);
    }

    /**
     *
     */
    protected function dbConnect()
    {
        $this->db->connect();
        $this->sql_error();
    }

    /**
     * @param int $i
     */
    protected function getServer($i = 0)
    {
        $i = (string)$i;
        $sql = 'SELECT s.`id` , s.`server` AS host, sp.`port` '
            . 'FROM `server` s, `server_port` sp, `network` n '
            . 'WHERE s.`id` = sp.`server_id` '
            . 'AND n.`id` = s.`network_id` '
            . 'AND n.`network` = "' . $this->server['network'] . '" '
            . 'ORDER BY s.`id` , sp.`port` '
            . 'LIMIT ' . $i . ', 1';
        $query = $this->sql_query($sql);
        $this->server['id'] = $this->db->result($query, 0, 'id');
        $this->server['host'] = $this->db->result($query, 0, 'host');
        $this->server['ip'] = @gethostbyname($this->server['host']);
        $this->server['port'] = $this->db->result($query, 0, 'port');
        $this->sql_query(
            'UPDATE `bot` SET `server_id` = "' . $this->server['id'] . '" WHERE `id` = "' . $this->bot['id'] . '"'
        );
    }

    /**
     * @return bool|void
     */
    public function connect()
    {
        if ($this->init === false) {
            if ($this->init() === false) {
                return false;
            }
        }

        $sql = 'SELECT count(*) AS number '
            . 'FROM `server` s, `server_port` sp, `network` n '
            . 'WHERE s.`id` = sp.`server_id` '
            . 'AND n.`id` = s.`network_id` '
            . 'AND n.`network` = "' . $this->server['network'] . '"';
        $query = $this->sql_query($sql);
        $n = $this->db->result($query, 0, 'number');
        $i = 0;
        $repeat = true;
        if ($n == 0) {
            $this->sysinfo('No ' . $this->server['network'] . ' server');
            return false;
        }
        while ($repeat) {
            $this->getServer($i);
            $this->sysinfo('Try to connect to ' . $this->server['host'] . ':' . $this->server['port']);
            $this->fp = @fsockopen(@gethostbyname($this->server['host']), $this->server['port'], $errno, $errstr);
            if ($this->fp === false) {
                $this->log('socket: ' . $errstr, 'error');
                $this->sysinfo('Connection failed');
                $i++;
            } else {
                $this->sysinfo('Connection success');
                $repeat = false;
                //$this->sql_query('INSERT INTO `connection` SET `time` = NOW()');
                //$this->bot['connectionid'] = $this->db->insert_id();
            }

            if ($i == $n) {
                $this->sysinfo('All attempts failed');
                return false;
            }
        }
        $this->time['irc_connect'] = $this->getMicrotime();
        //stream_set_blocking($this->fp, 0);
        if ($this->server['password'] !== null) {
            $this->write('PASS ' . $this->server['password']);
        }
        if ($this->bot['nick'] === null) {
            $this->setNick();
        }
        $this->write('USER PHP' . str_replace('.', '', phpversion()) . ' * * :' . $this->config['info']['name']);
        $this->write('NICK ' . $this->bot['nick']);
        $this->lastping = time();
        $this->nowrite = true;
        $this->run = true;
        $this->clear();
        $this->preform();
        return $this->run();
    }

    /**
     *
     */
    protected function reconnect()
    {
        $this->reconnect['channel'] = array();
        $query = $this->sql_query('SELECT `channel` FROM `channel` WHERE `bot_id` = "' . $this->bot['id'] . '"');
        while ($channel = $this->db->fetch_assoc($query)) {
            $this->reconnect['channel'][] = $channel['channel'];
        }

        fclose($this->fp);
        $this->connect();
    }

    /**
     *
     */
    protected function clear()
    {
        $this->sql_query('DELETE FROM `write` WHERE `bot_id` = "' . $this->bot['id'] . '"');
        $this->sql_query('DELETE FROM `channel` WHERE `bot_id` = "' . $this->bot['id'] . '"');
        $this->sql_query('DELETE FROM `channel_user` WHERE `bot_id` = "' . $this->bot['id'] . '"');
    }

    /**
     *
     */
    protected function preform()
    {
        $query = $this->sql_query(
            'SELECT `text` FROM `preform` WHERE `network` = "' . $this->server['network'] . '" ORDER BY `priority` DESC'
        );
        while ($copy = $this->db->fetch_assoc($query)) {
            $this->sql_query(
                'INSERT INTO `write` SET `text` = "' . $this->db->escape_string(
                    $copy['text']
                ) . '", `bot_id` = "' . $this->bot['id'] . '"'
            );
        }
        foreach ($this->reconnect['channel'] as $channel) {
            $this->join($channel);
        }
    }

    /**
     * @param $sql
     * @return mixed
     */
    protected function sql_query($sql)
    {
        $this->log($sql, 'sql');
        $res = $this->db->query($sql);
        $this->sql_error();
        return $res;
    }

    /**
     * @return mixed
     */
    protected function sql_error()
    {
        $errstr = $this->db->error();
        if ($errstr != '') {
            $this->log('sql: ' . $errstr, 'error');
        }
        return $errstr;
    }

    /**
     * @param $text
     * @param $type
     * @return null
     */
    protected function log($text, $type)
    {
        if ($this->config['logfile'][$type] !== true) {
            return null;
        }

        if ($this->config['dailylogfile'] === true) {
            $file = $type . '_log_' . date("Ymd", time()) . '.txt';
        } else {
            $file = $type . '_log.txt';
        }

        $handle = @fopen($this->config['logfiledirectory'] . $file, 'a+');
        if ($handle !== false) {
            fputs($handle, date("d.m.Y H:i:s", time()) . ' >>>> ' . $text . PHP_EOL);
            fflush($handle);
            fclose($handle);
        }
    }

    /**
     * @param $text
     */
    protected function write($text)
    {
        $output = trim($text) . PHP_EOL;
        echo '[' . date("H:i:s") . '] => ' . $output;
        fwrite($this->fp, $output);
        preg_match("/^([^ ]+).*?$/i", $text, $matches);
        $command = isset($matches[1]) ? $matches[1] : '';
        if (strtolower($command) == 'quit') {
            $this->run = false;
        }
    }

    /**
     * @return string
     */
    protected function read()
    {
        stream_set_timeout($this->fp, 10);
        $input = @fgets($this->fp, 4096);
        $text = trim($input);
        if ($text != '') {
            $this->log($text, 'socket');
            echo '[' . date("H:i:s") . '] <= ' . $text . PHP_EOL;
        }
        return $input;
    }

    /**
     *
     */
    protected function send()
    {
        $sql = 'SELECT `id`, `text` FROM `write` WHERE `bot_id` = "' . $this->bot['id'] . '" ORDER BY `id` LIMIT 0, 1';
        $query = $this->sql_query($sql);
        $send = $this->db->fetch_assoc($query);
        if ($send !== false) {
            if ($send['text'] != '') {
                preg_match_all("/\%([a-z0-9_]*)/i", $send['text'], $array, PREG_PATTERN_ORDER);
                foreach ($array[1] as $value) {
                    if (array_key_exists($value, $this->var)) {
                        $send['text'] = preg_replace(
                            '/%' . $value . '(\s|$)/i',
                            $this->var[$value] . "\\1",
                            $send['text']
                        );
                    }
                }
                $this->write($send['text']);
            }
            $sql_delete = 'DELETE FROM `write` WHERE `id` = "' . $send['id'] . '"';
            $this->sql_query($sql_delete);

            preg_match("/^([^\ ]+)(?:\ ([^\:].*?))?(?:\ \:(.*?))?(?:\r)?$/i", $send['text'], $matches);
            $command = isset($matches[1]) ? $matches[1] : '';
            $rest = isset($matches[2]) ? $matches[2] : '';
            $text = isset($matches[3]) ? $matches[3] : '';

            $sql_log = 'INSERT INTO log SET '
                . '`nick`      = "' . $this->db->escape_string($this->bot['nick']) . '", '
                . '`command`   = "' . $this->db->escape_string($command) . '", '
                . '`rest`      = "' . $this->db->escape_string($rest) . '", '
                . '`text`      = "' . $this->db->escape_string($text) . '", '
                . '`all`       = "' . $this->db->escape_string($send['text']) . '", '
                . '`network`   = "' . $this->db->escape_string($this->server['network']) . '", '
                . '`bot_id`    = "' . $this->bot['id'] . '", '
                //. '`connection_id` = "'.$this->bot['connectionid'].'", '
                . '`time`      = NOW(), '
                . '`direction` = "out"';
            $this->sql_query($sql_log);
        }
    }

    /**
     * @return bool|void
     */
    public function run()
    {
        while (!feof($this->fp)) {
            $input = $this->read();

            if (trim($input) != '') {
                if ($this->db->ping() === false) {
                    $this->db->close();
                    $this->db_connect();
                }
                if ($input{0} != ':') {
                    if (strpos(strtoupper($input), 'PING') !== false) {
                        $this->lastping = time();
                        $output = $input;
                        $output{1} = 'O';
                        $this->write($output);
                        unset($output);
                        $this->sql_query('UPDATE `bot` SET `ping` = NOW() WHERE `id` = "' . $this->bot['id'] . '"');
                    }
                } else {
                    $this->command($input);
                }
            }
            if ($this->nowrite === false && floor($this->getMicrotime() - $this->time['irc_connect']) > 10) {
                $this->send();
            }
            unset($input);
            $this->msleep(8);
            if ((time() - $this->lastping) > 600) {
                break;
            }
            if ($this->run === false) {
                return true;
            }
        }
        $this->sysinfo('Connection to server lost');
        sleep(20);
        $this->reconnect();
    }

    /**
     * @param $input
     */
    protected function command($input)
    {
        preg_match(
            "/^\:(?:([^\!\ \:]+)\!)?([^\!\ ]+)\ ([^\ ]+)(?:\ ([^\:].*?))?(?:\ \:(.*?))?(?:\r)?$/i",
            $input,
            $matches
        );
        $all = isset($matches[0]) ? $matches[0] : '';
        $nick = isset($matches[1]) ? $matches[1] : '';
        $host = isset($matches[2]) ? $matches[2] : '';
        $command = isset($matches[3]) ? $matches[3] : '';
        $rest = isset($matches[4]) ? $matches[4] : '';
        $text_ = isset($matches[5]) ? $matches[5] : '';

        $text = trim($text_);

        switch ($command) {
            case '001':
                $this->nowrite = false;
                break;
            case '311':
                $this->on311($rest);
                break;
            case '318':
                $this->on318();
                break;
            case '322':
                $this->on322($rest, $text);
                break;
            case '323':
                $this->on323();
                break;
            case '324':
                break; //(Channel-Modes)
            case '330':
                $this->on330($rest);
                break;
            case '332':
                $this->on332($rest, $text);
                break;
            case '353':
                $this->on353($rest, $text);
                break;
            case '432':
                $this->on432();
            case '433':
                $this->on433();
                break;
            case 'PRIVMSG':
                $this->onPrivmsg($nick, $host, $rest, $text);
                break;
            case 'NOTICE':
                $this->onNotice($nick, $text);
                break;
            case 'JOIN':
                $this->onJoin($nick, ($rest != '' ? $rest : $text));
                break;
            case 'PART':
                $this->onPart($nick, $rest);
                break;
            case 'QUIT':
                $this->onQuit($nick);
                break;
            case 'KICK':
                $this->onKick($nick, $rest);
                break;
            case 'NICK':
                $this->onNick($nick, $text);
                break;
            case 'MODE':
                $this->onMode($rest);
                break;
            case 'TOPIC':
                $this->onTopic($rest, $text);
                break;
            case 'INVITE':
                $this->onInvite($text, $host, $rest);
                break;
        }

        $sql_log = 'INSERT INTO log SET '
            . '`nick`      = "' . $this->db->escape_string($nick) . '", '
            . '`host`      = "' . $this->db->escape_string($host) . '", '
            . '`command`   = "' . $this->db->escape_string($command) . '", '
            . '`rest`      = "' . $this->db->escape_string($rest) . '", '
            . '`text`      = "' . $this->db->escape_string($text_) . '", '
            . '`all`       = "' . $this->db->escape_string($all) . '", '
            . '`network`   = "' . $this->db->escape_string($this->server['network']) . '", '
            . '`bot_id`    = "' . $this->bot['id'] . '", '
            //. '`connection_id` = "'.$this->bot['connectionid'].'", '
            . '`time`      = NOW(), '
            . '`direction` = "in"';
        $this->sql_query($sql_log);
    }

    /**
     * ERR_ERRONEUSNICKNAME
     */
    protected function on432()
    {
        $this->otherNick();
    }

    /**
     * ERR_NICKNAMEINUSE
     */
    protected function on433()
    {
        $this->otherNick();
    }

    /**
     *
     */
    protected function otherNick()
    {
        if ($this->nowrite === false) {
            return;
        }
        $this->setNick(null);
        $this->write('NICK ' . $this->bot['nick']);
    }

    /**
     * RPL_LIST
     * @param $rest
     * @param $text
     */
    protected function on322($rest, $text)
    {
        list($dummy, $channel, $anz_user) = explode(' ', $rest);
        $sql_list = 'INSERT INTO channellist SET '
            . '`channel` = "' . $this->db->escape_string($channel) . '", '
            . '`user`    = "' . $this->db->escape_string($anz_user) . '", '
            . '`topic`   = "' . $this->db->escape_string($text) . '", '
            . '`network` = "' . $this->db->escape_string($this->server['network']) . '", '
            . '`time`    = NOW()';
        $this->sql_query($sql_list);
    }

    /**
     * RPL_LISTEND
     */
    protected function on323()
    {
        $this->runPluginEvent(__FUNCTION__, array());
    }

    /**
     * RPL_WHOISUSER
     * <nick> <user> <host> * :<real name>
     * @param $rest
     */
    protected function on311($rest)
    {
        list($me, $nick, $user, $host) = explode(' ', $rest);
        $this->runPluginEvent(__FUNCTION__, array('nick' => $nick, 'host' => $user . '@' . $host));
    }

    /**
     * RPL_WHOISACCOUNT
     * @param $rest
     */
    protected function on330($rest)
    {
        list($me, $nick, $auth) = explode(' ', $rest);
        $this->runPluginEvent(__FUNCTION__, array('nick' => $nick, 'auth' => $auth));
    }

    /**
     * RPL_ENDOFWHOIS
     */
    protected function on318()
    {
        $this->runPluginEvent(__FUNCTION__, array());
    }

    /**
     * @param $nick
     * @param $host
     * @param $channel
     * @param $text
     * @return null
     */
    protected function onPrivmsg($nick, $host, $channel, $text)
    {
        if (preg_match("/\x01([A-Z]+)( [0-9\.]+)?\x01/i", $text, $matches)) {
            if ($this->config['ctcp'] === false) {
                return null;
            }
            $send = '';
            switch ($matches[1]) {
                case 'PING':
                    $send = 'PING' . $matches[2];
                    break;
                case 'VERSION':
                    $send = 'VERSION ' . $this->version['bot'];
                    break;
                case 'TIME':
                    $send = 'TIME ' . date('D M d H:i:s Y T');
                    break;
                case 'FINGER':
                    $send = 'FINGER ' . $this->config['info']['name'] . (isset($this->config['info']['homepage']) ? ' (' . $this->config['info']['homepage'] . ')' : '') . ' Idle ' . round(
                        $this->getMicrotime() - $this->time['irc_connect']
                    ) . ' seconds';
                    break;
                default:
                    return null;
            }
            if (empty($send) === false) {
                $this->notice($nick, "\x01" . $send . "\x01");
            }
        } else {
            $splitText = explode(' ', $text);
            switch ($splitText[0]) {
                case '!load':
                    if (empty($splitText[1]) === false) {
                        if ($this->isAdmin($nick, $host) === true) {
                            if (preg_match('/^[a-z]+$/i', $splitText[1]) > 0) {
                                $this->loadPlugin(
                                    $splitText[1],
                                    array('nick' => $nick, 'host' => $host, 'channel' => $channel, 'text' => $text)
                                );
                            }
                        }
                    }
                    break;
                default:
                    $this->runPluginEvent(
                        __FUNCTION__,
                        array('nick' => $nick, 'host' => $host, 'channel' => $channel, 'text' => $text)
                    );
                    return null;
            }
        }
    }

    /**
     * @param $nick
     * @param $text
     */
    protected function onNotice($nick, $text)
    {
        $this->runPluginEvent(__FUNCTION__, array('nick' => $nick, 'text' => $text));
    }

    /**
     * @param $nick
     * @param $text
     */
    protected function onNick($nick, $text)
    {
        if ($nick == $this->var['me']) {
            $this->setNick($text);
        }
        $sql = 'UPDATE `channel_user` SET `user` = "' . $this->db->escape_string(
            $text
        ) . '" WHERE `bot_id` = "' . $this->bot['id'] . '" AND `user` = "' . $this->db->escape_string($nick) . '"';
        $this->sql_query($sql);
        $this->runPluginEvent(__FUNCTION__, array('nick' => $nick, 'text' => $text));
    }

    /**
     * RPL_NAMREPLY
     * @param $rest
     * @param $text
     */
    protected function on353($rest, $text)
    {
        list($me, $dummy, $channel) = explode(' ', $rest);
        $user_array = explode(' ', $text);
        foreach ($user_array as $user) {
            preg_match("/^([\+\@])?([^\+\@]+)$/i", $user, $matches);
            $this->sql_query(
                'INSERT INTO `channel_user` SET `user` = "' . $this->db->escape_string(
                    $matches[2]
                ) . '", `mode` = "' . $this->db->escape_string(
                    $matches[1]
                ) . '", `channel` = "' . $this->db->escape_string($channel) . '", `bot_id` = "' . $this->bot['id'] . '"'
            );
        }
    }

    /**
     * RPL_TOPIC
     * @param $rest
     * @param $text
     */
    protected function on332($rest, $text)
    {
        list($me, $channel) = explode(' ', $rest);
        $this->onTopic($channel, $text);
    }

    /**
     * @param $channel
     * @param $topic
     */
    protected function onTopic($channel, $topic)
    {
        $sql = 'UPDATE `channel` SET `topic` = "' . $this->db->escape_string(
            $topic
        ) . '" WHERE `bot_id` = "' . $this->bot['id'] . '" AND `channel` = "' . $this->db->escape_string(
            $channel
        ) . '"';
        $this->sql_query($sql);
    }

    /**
     * @param $nick
     * @param $channel
     */
    protected function onJoin($nick, $channel)
    {
        if ($nick == $this->var['me']) {
            $this->sql_query(
                'INSERT INTO `channel` SET `channel` = "' . $this->db->escape_string(
                    $channel
                ) . '", `bot_id` = "' . $this->bot['id'] . '"'
            );
            $this->mode($channel);
        } else {
            $this->sql_query(
                'INSERT INTO `channel_user` SET `user` = "' . $this->db->escape_string(
                    $nick
                ) . '", `channel` = "' . $this->db->escape_string($channel) . '", `bot_id` = "' . $this->bot['id'] . '"'
            );
        }
        $this->runPluginEvent(__FUNCTION__, array('nick' => $nick, 'channel' => $channel));
    }

    /**
     * @param $bouncer
     * @param $rest
     */
    protected function onKick($bouncer, $rest)
    {
        list($channel, $nick) = explode(' ', $rest);
        $me = $nick == $this->var['me'] ? true : false;
        $this->onPart($nick, $channel);
        if ($this->config['autorejoin'] === true && $me === true) {
            $this->join($channel);
        }
        $this->runPluginEvent(
            __FUNCTION__,
            array('channel' => $channel, 'me' => $me, 'nick' => $nick, 'bouncer' => $bouncer)
        );
    }

    /**
     * @param $nick
     * @param $channel
     */
    protected function onPart($nick, $channel)
    {
        $me = $nick == $this->var['me'] ? true : false;
        if ($me === true) {
            $this->sql_query(
                'DELETE FROM `channel` WHERE `channel` = "' . $this->db->escape_string(
                    $channel
                ) . '" AND `bot_id` = "' . $this->bot['id'] . '"'
            );
            $this->sql_query(
                'DELETE FROM `channel_user` WHERE `channel` = "' . $this->db->escape_string(
                    $channel
                ) . '" AND `bot_id` = "' . $this->bot['id'] . '"'
            );
        } else {
            $this->sql_query(
                'DELETE FROM `channel_user` WHERE `user` = "' . $this->db->escape_string(
                    $nick
                ) . '" AND `channel` = "' . $this->db->escape_string(
                    $channel
                ) . '" AND `bot_id` = "' . $this->bot['id'] . '"'
            );
        }
        $this->runPluginEvent(__FUNCTION__, array('channel' => $channel, 'me' => $me, 'nick' => $nick));
    }

    /**
     * @param $nick
     */
    protected function onQuit($nick)
    {
        $this->sql_query(
            'DELETE FROM `channel_user` WHERE `user` = "' . $this->db->escape_string(
                $nick
            ) . '" AND `bot_id` = "' . $this->bot['id'] . '"'
        );
        $this->runPluginEvent(__FUNCTION__, array('nick' => $nick));
    }

    /**
     * @param $mode
     */
    protected function onMode($mode)
    {
        $array = explode(' ', $mode);
        $channel = $array[0];
        /* TODO */
    }

    /**
     * @param $channel
     * @param $host
     * @param $rest
     */
    protected function onInvite($channel, $host, $rest)
    {
        $this->runPluginEvent(__FUNCTION__, array('channel' => $channel));
    }

    /**
     * @param $to
     * @param $text
     */
    public function privmsg($to, $text)
    {
        $this->sql_query(
            'INSERT INTO `write` SET `text` = "' . $this->db->escape_string(
                'PRIVMSG ' . $to . ' :' . $text
            ) . '", `bot_id` = "' . $this->bot['id'] . '"'
        );
    }

    /**
     * @param $to
     * @param $text
     */
    public function notice($to, $text)
    {
        $this->sql_query(
            'INSERT INTO `write` SET `text` = "' . $this->db->escape_string(
                'NOTICE ' . $to . ' :' . $text
            ) . '", `bot_id` = "' . $this->bot['id'] . '"'
        );
    }

    /**
     * @param $text
     */
    public function quit($text)
    {
        $this->sql_query(
            'INSERT INTO `write` SET `text` = "' . $this->db->escape_string(
                'QUIT :' . $text
            ) . '", `bot_id` = "' . $this->bot['id'] . '"'
        );
    }

    /**
     * @param null $text
     */
    public function mode($text = null)
    {
        $this->sql_query(
            'INSERT INTO `write` SET `text` = "' . $this->db->escape_string(
                'MODE' . ($text === null ? '' : ' ' . $text)
            ) . '", `bot_id` = "' . $this->bot['id'] . '"'
        );
    }

    /**
     * @param $channel
     */
    public function join($channel)
    {
        $this->sql_query(
            'INSERT INTO `write` SET `text` = "' . $this->db->escape_string(
                'JOIN ' . $channel
            ) . '", `bot_id` = "' . $this->bot['id'] . '"'
        );
    }

    /**
     * @param $channel
     */
    public function part($channel)
    {
        $this->sql_query(
            'INSERT INTO `write` SET `text` = "' . $this->db->escape_string(
                'PART ' . $channel
            ) . '", `bot_id` = "' . $this->bot['id'] . '"'
        );
    }

    /**
     * @param $nick
     */
    public function whois($nick)
    {
        $this->sql_query(
            'INSERT INTO `write` SET `text` = "' . $this->db->escape_string(
                'WHOIS :' . $nick
            ) . '", `bot_id` = "' . $this->bot['id'] . '"'
        );
    }

    /**
     *
     */
    public function channellist()
    {
        $this->sql_query(
            'DELETE FROM `channellist` WHERE `network` = "' . $this->db->escape_string($this->server['network']) . '"'
        );
        $this->sql_query(
            'INSERT INTO `write` SET `text` = "' . $this->db->escape_string(
                'LIST'
            ) . '", `bot_id` = "' . $this->bot['id'] . '"'
        );
    }

    /**
     * @param PluginAuth $object
     */
    public function registerAuth(PluginAuth $object)
    {
        $this->auth = $object;
    }

    /**
     * @param $auth
     * @return bool
     */
    public function getAuthLevel($auth)
    {
        $sql = 'SELECT `authlevel` FROM `user` WHERE `network` = "' . $this->server['network'] . '" AND `authname` = "' . strtolower(
            $auth
        ) . '"';
        $query = $this->sql_query($sql);
        if ($query !== false) {
            $row = $this->db->fetch_row($query);
            if ($row) {
                return $row[0];
            }
        }
        return false;
    }

    /**
     * @param $nick
     * @param $host
     * @return mixed
     */
    public function isAdmin($nick, $host)
    {
        return $this->auth->isAdmin($nick, $host);
    }

    /**
     * @param $nick
     * @param $host
     * @return mixed
     */
    public function isMember($nick, $host)
    {
        return $this->auth->isMember($nick, $host);
    }

    /**
     *
     */
    protected function autoloadPlugins()
    {
        $this->loadPlugin('auth');
        foreach ($this->config['plugins']['autoload'] as $plugin) {
            $this->loadPlugin($plugin);
        }
    }

    /**
     * @param $name
     * @param null $data
     */
    protected function loadPlugin($name, $data = null)
    {
        $pluginClass = 'Cerberus\\Plugins\\Plugin' . ucfirst($name);

        if (class_exists($pluginClass) === true) {
            if (array_key_exists($pluginClass, $this->loaded['classes']) === false) {
                $plugin = new $pluginClass($this);
                if (is_subclass_of($pluginClass, 'Cerberus\\Plugin') === true) {
                    $this->sysinfo('Load Plugin: ' . $name);
                    $this->loaded['classes'][$pluginClass] = $plugin->onLoad($data);
                } else {
                    $this->sysinfo($name . ' isn\'t a PluginClass.');
                }
            } else {
                $this->sysinfo('Plugin "' . $name . '" is already loaded.');
            }
        } else {
            $this->sysinfo($name . ' don\'t exists.');
        }
    }

    /**
     * @param $event
     * @param $data
     */
    protected function runPluginEvent($event, $data)
    {
        if (array_key_exists($event, $this->pluginevents)) {
            for ($priority = 10; $priority > 0; $priority--) {
                if (array_key_exists($priority, $this->pluginevents[$event])) {
                    foreach ($this->pluginevents[$event][$priority] as $pluginClass) {
                        $pluginClass->$event($data);
                    }
                }
            }
        }
    }

    /**
     * @param $event
     * @param $object
     * @param int $priority
     */
    public function addEvent($event, $object, $priority = 5)
    {
        $this->pluginevents[$event][$priority][] = $object;
    }
}
