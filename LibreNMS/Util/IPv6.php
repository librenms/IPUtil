<?php
/**
 * IPv6.php
 *
 * IPv6 parsing class
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package    LibreNMS
 * @link       http://librenms.org
 * @copyright  2017 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace LibreNMS\Util;

use LibreNMS\Exceptions\InvalidIpException;

class IPv6 extends IP
{
    /**
     * IPv6 constructor.
     * @param $ipv6
     * @throws InvalidIpException
     */
    public function __construct($ipv6)
    {
        $this->host_bits = 128;
        list($this->ip, $this->cidr) = $this->extractCidr($ipv6);

        if (!self::isValid($this->ip)) {
            throw new InvalidIpException("$ipv6 is not a valid ipv4 address");
        }

        $this->ip = $this->compressed();  // store in compressed format
    }

    /**
     * Check if the supplied IP is valid.
     * @param string $ipv6
     * @param bool $exclude_reserved Exclude reserved IP ranges.
     * @return bool
     */
    public static function isValid($ipv6, $exclude_reserved = false)
    {
        $filter = FILTER_FLAG_IPV6;
        if ($exclude_reserved) {
            $filter |= FILTER_FLAG_NO_RES_RANGE;
        }

        return filter_var($ipv6, FILTER_VALIDATE_IP, $filter) !== false;
    }

    /**
     * Remove extra 0s from this IPv6 address to make it easier to read.
     * @return string|false
     */
    public function compressed()
    {
        return inet_ntop(inet_pton($this->ip));
    }

    /**
     * Get the network address of this IP
     * @param int $cidr If not given will use the cidr stored with this IP
     * @return string
     */
    public function getNetworkAddress($cidr = null)
    {
        if (is_null($cidr)) {
            $cidr = $this->cidr;
        }

        $net_bytes = unpack('n*', inet_pton($this->ip));

        foreach ($net_bytes as $index => $byte) {
            $shift = min($cidr - 16 * ($index - 1), 16);
            if ($shift > 0) {
                $mask = ~(0xffff >> $shift) & 0xffff;
                $net_bytes[$index] = $byte & $mask;
            } else {
                $net_bytes[$index] = 0;
            }
        }
        array_unshift($net_bytes, 'n*'); // add pack format
        return inet_ntop(call_user_func_array('pack', $net_bytes));
    }

    /**
     * Check if this IP address is contained inside the network
     * @param string $network should be in cidr format.
     * @return mixed
     */
    public function inNetwork($network)
    {
        list($net, $cidr) = $this->extractCidr($network);

        if (!self::isValid($net)) {
            return false;
        }

        $net_bytes = unpack('n*', inet_pton($net));
        $ip_bytes = unpack('n*', inet_pton($this->ip));
        if ($net_bytes === false || $ip_bytes === false) {
            return false;
        }

        // unpack indexes start at 1 and go to 8 for an ipv6
        for ($index = 1; $index <= 8; $index++) {
            $shift = $cidr - 16 * ($index - 1);
            if ($shift > 0) {
                $mask = ~(0xffff >> $shift) & 0xffff;
                if (($net_bytes[$index] & $mask) != ($ip_bytes[$index] & $mask)) {
                    return false;
                }
            } else {
                break; // we've passed the network bits, who cares about the rest.
            }
        }
        return true;
    }

    /**
     * Expand this IPv6 address to it's full IPv6 representation. For example: ::1 -> 0000:0000:0000:0000:0000:0000:0000:0001
     * @return string
     */
    public function uncompressed()
    {
        // remove ::
        $replacement = ':' . str_repeat('0000:', 8 - substr_count($this->ip, ':'));
        $ip = str_replace('::', $replacement, $this->ip);

        // zero pad
        $parts = explode(':', $ip, 8);
        return implode(':', array_map(function ($section) {
            return str_pad($section, 4, '0', STR_PAD_LEFT);
        }, $parts));
    }

    /**
     * Convert this IP to an snmp index hex encoded
     *
     * @return string
     */
    public function toSnmpIndex()
    {
        $ipv6_split = str_split(str_replace(':', '', $this->uncompressed()), 2);
        return implode('.', array_map('hexdec', $ipv6_split));
    }
}