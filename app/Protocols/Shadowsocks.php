<?php

namespace App\Protocols;

use App\Support\AbstractProtocol;
use App\Models\Server;
use App\Services\OutlineService;

class Shadowsocks extends AbstractProtocol
{
    public $flags = ['shadowsocks'];

    public $allowedProtocols = [
        Server::TYPE_SHADOWSOCKS,
        Server::TYPE_OUTLINE,
    ];

    public function handle()
    {
        $servers = $this->servers;
        $user = $this->user;

        $configs = [];
        $subs = [];
        $subs['servers'] = [];
        $subs['bytes_used'] = '';
        $subs['bytes_remaining'] = '';

        $bytesUsed = $user['u'] + $user['d'];
        $bytesRemaining = $user['transfer_enable'] - $bytesUsed;

        foreach ($servers as $item) {
            if (
                $item['type'] === 'shadowsocks'
                && in_array(data_get($item, 'protocol_settings.cipher'), ['aes-128-gcm', 'aes-256-gcm', 'aes-192-gcm', 'chacha20-ietf-poly1305'])
            ) {
                array_push($configs, self::SIP008($item, $user));
            }
            if ($item['type'] === Server::TYPE_OUTLINE) {
                if ($outline = self::SIP008Outline($item, $user)) {
                    array_push($configs, $outline);
                }
            }
        }

        $subs['version'] = 1;
        $subs['bytes_used'] = $bytesUsed;
        $subs['bytes_remaining'] = $bytesRemaining;
        $subs['servers'] = array_merge($subs['servers'], $configs);

        return response()->json($subs)
            ->header('content-type', 'application/json');
    }

    public static function SIP008($server, $user)
    {
        $config = [
            "id" => $server['id'],
            "remarks" => $server['name'],
            "server" => $server['host'],
            "server_port" => $server['port'],
            "password" => $server['password'],
            "method" => data_get($server, 'protocol_settings.cipher')
        ];
        return $config;
    }

    public static function SIP008Outline($server, $user)
    {
        $server = OutlineService::asShadowsocksServer($server['password'], $server);
        return $server ? self::SIP008($server, $user) : null;
    }
}
