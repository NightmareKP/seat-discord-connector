<?php

namespace Warlof\Seat\Connector\Drivers\Discord\Http\Controllers;

use Laravel\Socialite\Facades\Socialite;
use Seat\Web\Http\Controllers\Controller;
use SocialiteProviders\Manager\Config;
use Warlof\Seat\Connector\Drivers\Discord\Driver\DiscordClient;
use Warlof\Seat\Connector\Exceptions\DriverSettingsException;
use Warlof\Seat\Connector\Models\User;

/**
 * Class RegistrationController.
 *
 * @package Warlof\Seat\Connector\Discord\Http\Controllers
 */
class RegistrationController extends Controller
{
    /**
     * @return mixed
     * @throws \Seat\Services\Exceptions\SettingException
     * @throws \Warlof\Seat\Connector\Exceptions\DriverSettingsException
     */
    public function redirectToProvider()
    {
        $settings  = setting('seat-connector.drivers.discord', true);

        if (is_null($settings) || ! is_object($settings))
            throw new DriverSettingsException('The Driver has not been configured yet.');

        if (! property_exists($settings, 'client_id') || is_null($settings->client_id) || $settings->client_id == '')
            throw new DriverSettingsException('Parameter client_id is missing.');

        if (! property_exists($settings, 'client_secret') || is_null($settings->client_secret) || $settings->client_secret == '')
            throw new DriverSettingsException('Parameter client_secret is missing.');

        $redirect_uri = route('seat-connector.drivers.discord.callback');

        $config = new Config($settings->client_id, $settings->client_secret, $redirect_uri);

        return Socialite::with('discord')->setConfig($config)->redirect();
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Warlof\Seat\Connector\Exceptions\DriverSettingsException
     * @throws \Seat\Services\Exceptions\SettingException
     */
    public function handleProviderCallback()
    {
        // retrieve driver instance
        $client = DiscordClient::getInstance();

        // retrieve authenticated user
        $socialite_user = Socialite::driver('discord')->user();

        // update or create the connector user
        $driver_user = User::updateOrCreate([
            'connector_type' => 'discord',
            'connector_id'   => $socialite_user->id,
        ], [
            'connector_name' => $socialite_user->nickname,
            'group_id'       => auth()->user()->group_id,
            'unique_id'      => $socialite_user->email,
        ]);

        // invite the user to the guild using both nickname and roles
        $client->sendCall('PUT', '/guilds/{guild.id}/members/{user.id}', [
            'guild.id'     => $client->getGuildId(),
            'user.id'      => $socialite_user->id,
            'nick'         => $driver_user->buildConnectorNickname(),
            'roles'        => $driver_user->allowedSets(),
            'access_token' => $socialite_user->token,
        ]);

        // send the user to the guild
        return redirect()->route('seat-connector.identities');
    }
}
