<?php

namespace MCTeam;

use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\Callbacks;
use ManiaControl\Callbacks\CallbackManager;
use ManiaControl\Commands\CommandListener;
use ManiaControl\ManiaControl;
use ManiaControl\Players\Player;
use ManiaControl\Players\PlayerManager;
use ManiaControl\Plugins\Plugin;
use ManiaControl\Settings\Setting;
use ManiaControl\Settings\SettingManager;
use Maniaplanet\DedicatedServer\Xmlrpc\GameModeException;

/**
 * Dynamic Point Limit Plugin
 *
 * @author    ManiaControl Team <mail@maniacontrol.com>
 * @copyright 2014 ManiaControl Team
 * @license   http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */

/**
* Ankou's changes :
* - DodgeBall Points management
* - Limit Change Message, deactivatable
*/


// TODO: test setpointlimit command
class DynamicPointLimitPlugin implements CallbackListener, CommandListener, Plugin
{
	/*
	 * Constants
	 */
	const ID      = 21;
	const VERSION = 0.3;
	const NAME    = 'Dynamic Point Limit Plugin';
	const AUTHOR  = 'MCTeam';


	const SETTING_DISPLAY_CHANGES        = 'Display changes in chat';
	const SETTING_POINT_LIMIT_MULTIPLIER = 'Point Limit Multiplier';
	const SETTING_POINT_LIMIT_OFFSET     = 'Point Limit Offset';
	const SETTING_MIN_POINT_LIMIT        = 'Minimum Point Limit';
	const SETTING_MAX_POINT_LIMIT        = 'Maximum Point Limit';
	const SETTING_ACCEPT_OTHER_MODES     = 'Activate in other Modes than Royal';


	const SETTING_DB_MODE                = 'DodgeBall mode';
	const SETTING_DB_MINPT				 = 'DB Minimum Point Limit';
	const SETTING_DB_1PLAYER			 = 'DB 1 | Less than n players';
	const SETTING_DB_1POINTS			 = 'DB 1 | Number of point limit';
	const SETTING_DB_2PLAYER			 = 'DB 2 | Less than n players';
	const SETTING_DB_2POINTS			 = 'DB 2 | Number of point limit';
	const SETTING_DB_3PLAYER			 = 'DB 3 | Less than n players';
	const SETTING_DB_3POINTS			 = 'DB 3 | Number of point limit';


	const CACHE_SPEC_STATUS = 'SpecStatus';

	const SCRIPT_SETTING_MAP_POINTS_LIMIT = 'S_PointsLimit';

	/*
	 * Private properties
	 */
	/** @var ManiaControl $maniaControl */
	private $maniaControl = null;
	private $lastPointLimit = null;
	private $staticMode = null;

	/**
	 * @see \ManiaControl\Plugins\Plugin::prepare()
	 */
	public static function prepare(ManiaControl $maniaControl)
	{
		$maniaControl->getSettingManager()
			->initSetting(get_class(), self::SETTING_DISPLAY_CHANGES, false);
		$maniaControl->getSettingManager()
			->initSetting(get_class(), self::SETTING_POINT_LIMIT_MULTIPLIER, 10);
		$maniaControl->getSettingManager()
			->initSetting(get_class(), self::SETTING_POINT_LIMIT_OFFSET, 0);
		$maniaControl->getSettingManager()
			->initSetting(get_class(), self::SETTING_MIN_POINT_LIMIT, 30);
		$maniaControl->getSettingManager()
			->initSetting(get_class(), self::SETTING_MAX_POINT_LIMIT, 200);
		$maniaControl->getSettingManager()
			->initSetting(get_class(), self::SETTING_ACCEPT_OTHER_MODES, false);

		//DodgeBall Settings

		$maniaControl->getSettingManager()
			->initSetting(get_class(), self::SETTING_DB_MODE, false);

		$maniaControl->getSettingManager()
			->initSetting(get_class(), self::SETTING_DB_1PLAYER, 4);
		$maniaControl->getSettingManager()
			->initSetting(get_class(), self::SETTING_DB_1POINTS, 4);

		$maniaControl->getSettingManager()
			->initSetting(get_class(), self::SETTING_DB_2PLAYER, 6);
		$maniaControl->getSettingManager()
			->initSetting(get_class(), self::SETTING_DB_2POINTS, 3);

		$maniaControl->getSettingManager()
			->initSetting(get_class(), self::SETTING_DB_3PLAYER, 0);
		$maniaControl->getSettingManager()
			->initSetting(get_class(), self::SETTING_DB_3POINTS, 0);
		$maniaControl->getSettingManager()
			->initSetting(get_class(), self::SETTING_DB_MINPT, 2);
	}


	/**
	 * @see \ManiaControl\Plugins\Plugin::load()
	 */
	public function load(ManiaControl $maniaControl)
	{
		$this->maniaControl = $maniaControl;

		$allowOthers = $this->maniaControl->getSettingManager()
			->getSettingValue($this, self::SETTING_ACCEPT_OTHER_MODES);
		if (!$allowOthers && $this->maniaControl->getServer()->titleId !== 'SMStormRoyal@nadeolabs')
		{
			$error = 'This plugin only supports Royal (check Settings)!';
			throw new \Exception($error);
		}

		$this->maniaControl->getCallbackManager()
			->registerCallbackListener(PlayerManager::CB_PLAYERCONNECT, $this, 'updatePointLimit');
		$this->maniaControl->getCallbackManager()
			->registerCallbackListener(PlayerManager::CB_PLAYERDISCONNECT, $this, 'updatePointLimit');
		$this->maniaControl->getCallbackManager()
			->registerCallbackListener(PlayerManager::CB_PLAYERINFOCHANGED, $this, 'handlePlayerInfoChangedCallback');

		//		$this->maniaControl->getCallbackManager()
		//		                   ->registerCallbackListener(Callbacks::BEGINROUND, $this, 'updatePointLimit');

		$this->maniaControl->getCallbackManager()
			->registerCallbackListener(CallBackManager::CB_MP_BEGINROUND, $this, 'updatePointLimit');

		$this->maniaControl->getCallbackManager()
			->registerCallbackListener(Callbacks::BEGINMAP, $this, 'handleBeginMap');
		$this->maniaControl->getCallbackManager()
			->registerCallbackListener(SettingManager::CB_SETTING_CHANGED, $this, 'handleSettingChangedCallback');

		$this->maniaControl->getCommandManager()
			->registerCommandListener('setpointlimit', $this, 'commandSetPointlimit', true, 'Setpointlimit XXX or auto');

		$this->updatePointLimit();
	}

	/**
	 * Update Point Limit
	 */
	public function updatePointLimit()
	{
		if ($this->staticMode)
		{
			return;
		}
		$numberOfPlayers = $this->maniaControl->getPlayerManager()
			->getPlayerCount();

		$display = $this->maniaControl->getSettingManager()
			->getSettingValue($this, self::SETTING_DISPLAY_CHANGES);

		//Royal Settings
		$multiplier = $this->maniaControl->getSettingManager()
			->getSettingValue($this, self::SETTING_POINT_LIMIT_MULTIPLIER);
		$offset     = $this->maniaControl->getSettingManager()
			->getSettingValue($this, self::SETTING_POINT_LIMIT_OFFSET);
		$minValue   = $this->maniaControl->getSettingManager()
			->getSettingValue($this, self::SETTING_MIN_POINT_LIMIT);
		$maxValue   = $this->maniaControl->getSettingManager()
			->getSettingValue($this, self::SETTING_MAX_POINT_LIMIT);

		//DodgeBall settings
		$dbMode     = $this->maniaControl->getSettingManager()
			->getSettingValue($this, self::SETTING_DB_MODE);
		$db1Players = $this->maniaControl->getSettingManager()
			->getSettingValue($this, self::SETTING_DB_1PLAYER);
		$db1Points  = $this->maniaControl->getSettingManager()
			->getSettingValue($this, self::SETTING_DB_1POINTS);
		$db2Players = $this->maniaControl->getSettingManager()
			->getSettingValue($this, self::SETTING_DB_2PLAYER);
		$db2Points  = $this->maniaControl->getSettingManager()
			->getSettingValue($this, self::SETTING_DB_2POINTS);
		$db3Players = $this->maniaControl->getSettingManager()
			->getSettingValue($this, self::SETTING_DB_3PLAYER);
		$db3Points  = $this->maniaControl->getSettingManager()
			->getSettingValue($this, self::SETTING_DB_3POINTS);
		$db3MinPoints  = $this->maniaControl->getSettingManager()
			->getSettingValue($this, self::SETTING_DB_3POINTS);
		$dbMinPoints  = $this->maniaControl->getSettingManager()
			->getSettingValue($this, self::SETTING_DB_MINPT);

		if ($dbMode) //DodgeBall
		{
			if ($db1Players !== 0 && $db1Points !== 0 && $numberOfPlayers < $db1Players)
			{
				$pointLimit = $db1Points;
			}
			else if ($db2Players !== 0 && $db2Points !== 0 && $numberOfPlayers < $db2Players)
			{
				$pointLimit = $db2Points;
			}
			else if ($db3Players !== 0 && $db3Points !== 0 && $numberOfPlayers < $db3Players)
			{
				$pointLimit = $db3Points;
			}
			else
			{
				$pointLimit = $dbMinPoints;
			}
		}
		else //Royal
		{
			$pointLimit = $offset + $numberOfPlayers * $multiplier;
			if ($pointLimit < $minValue)
			{
				$pointLimit = $minValue;
			}
			if ($pointLimit > $maxValue)
			{
				$pointLimit = $maxValue;
			}
		}
		$pointLimit = (int)$pointLimit;



		if ($this->lastPointLimit !== $pointLimit)
		{
			try
			{
				$this->maniaControl->getClient()
					 ->setModeScriptSettings(array(self::SCRIPT_SETTING_MAP_POINTS_LIMIT => $pointLimit));
				if ($display)
				{
					$message = "Dynamic PointLimit changed to: {$pointLimit}!";
					if ($this->lastPointLimit !== null)
					{
						$message .= " (From {$this->lastPointLimit})";
					}
					$this->maniaControl->getChat()
						->sendInformation($message);
				}
				$this->lastPointLimit = $pointLimit;
			}
			catch (GameModeException $exception)
			{
				$this->maniaControl->getChat()
					->sendExceptionToAdmins($exception);
			}
		}
	}

	/**
	 * Handle SetPointLimit Command
	 *
	 * @param array  $chatCallback
	 * @param Player $player
	 */
	public function commandSetPointlimit(array $chatCallback, Player $player)
	{
		$commandParts = explode(' ', $chatCallback[1][2]);
		if (count($commandParts) < 2)
		{
			$this->maniaControl->getChat()
				->sendUsageInfo('Example: //setpointlimit auto', $player);
			return;
		}
		$value = strtolower($commandParts[1]);
		if ($value === "auto")
		{
			$this->staticMode = false;
			$this->maniaControl->getChat()
				->sendInformation('Enabled Dynamic PointLimit!');
			$this->updatePointLimit();
		}
		else
		{
			if (is_numeric($value))
			{
				$value = (int)$value;
				if ($value <= 0)
				{
					$this->maniaControl->getChat()
						->sendError('PointLimit needs to be greater than Zero.', $player);
					return;
				}
				try
				{
					$this->maniaControl->getClient()
						->setModeScriptSettings(array(self::SCRIPT_SETTING_MAP_POINTS_LIMIT => $value));
					$this->staticMode     = true;
					$this->lastPointLimit = $value;
					$this->maniaControl->getChat()
						->sendInformation("PointLimit changed to: {$value} (Fixed)");
				}
				catch (GameModeException $exception)
				{
					$this->maniaControl->getChat()
						->sendException($exception, $player);
				}
			}
			else
			{
				$this->maniaControl->getChat()
					->sendUsageInfo('Example: //setpointlimit 150', $player);
			}
		}
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::unload()
	 */
	public function unload()
	{
	}

	/**
	 * Handle Setting Changed Callback
	 *
	 * @param Setting $setting
	 */
	public function handleSettingChangedCallback(Setting $setting)
	{
		if (!$setting->belongsToClass($this))
		{
			return;
		}
		$this->updatePointLimit();
	}

	/**
	 * Handle BeginMap Callback
	 */
	public function handleBeginMap()
	{
		if ($this->staticMode && !is_null($this->lastPointLimit))
		{
			// Refresh static point limit in case it has been reset
			try
			{
				$this->maniaControl->getClient()
					->setModeScriptSettings(array(self::SCRIPT_SETTING_MAP_POINTS_LIMIT => $this->lastPointLimit));
				$message = "PointLimit fixed at {$this->lastPointLimit}.";
				$this->maniaControl->getChat()
					->sendInformation($message);
			}
			catch (GameModeException $e)
			{
				$this->lastPointLimit = null;
			}
		}
	}


	/**
	 * Handle Player Info Changed Callback
	 *
	 * @param Player $player
	 */
	public function handlePlayerInfoChangedCallback(Player $player)
	{
		$lastSpecStatus = $player->getCache($this, self::CACHE_SPEC_STATUS);
		$newSpecStatus  = $player->isSpectator;
		if ($newSpecStatus === $lastSpecStatus && $lastSpecStatus !== null)
		{
			return;
		}
		$player->setCache($this, self::CACHE_SPEC_STATUS, $newSpecStatus);
		$this->updatePointLimit();
	}



	//==[Getters/Setters]========================================================================================================================


	/**
	 * @see \ManiaControl\Plugins\Plugin::getId()
	 */
	public static function getId()
	{
		return self::ID;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getName()
	 */
	public static function getName()
	{
		return self::NAME;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getVersion()
	 */
	public static function getVersion()
	{
		return self::VERSION;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getAuthor()
	 */
	public static function getAuthor()
	{
		return self::AUTHOR;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getDescription()
	 */
	public static function getDescription()
	{
		return 'Plugin offering a dynamic Point Limit according to the Number of Players on the Server.';
	}
}
