<?php
/**
 * ManiaLive - TrackMania dedicated server manager in PHP
 * 
 * @copyright   Copyright (c) 2009-2011 NADEO (http://www.nadeo.com)
 * @license     http://www.gnu.org/licenses/lgpl.html LGPL License 3
 * @version     $Revision: 1709 $:
 * @author      $Author: svn $:
 * @date        $Date: 2011-01-07 14:06:13 +0100 (ven., 07 janv. 2011) $:
 */

namespace ManiaLive\Data;

use ManiaLive\Application\SilentCriticalEventException;
use ManiaLive\Application\CriticalEventException;
use ManiaLive\DedicatedApi\Structures\Challenge;
use ManiaLive\Event\Dispatcher;
use ManiaLive\Utilities\Console;
use ManiaLive\DedicatedApi\Structures\Player;
use ManiaLive\DedicatedApi\Connection;

/**
 * Contain every important data about the server
 */
class Storage extends \ManiaLive\Utilities\Singleton implements \ManiaLive\DedicatedApi\Callback\Listener, \ManiaLive\Application\Listener
{
	protected $disconnetedPlayers = array();

	public $players = array();
	public $spectators = array();
	public $ranking = array();
	public $challenges;
	/**
	 * @var \ManiaLive\DedicatedApi\Structures\Challenge
	 */
	public $currentChallenge;
	/**
	 * @var \ManiaLive\DedicatedApi\Structures\Challenge
	 */
	public $nextChallenge;
	/**
	 * @var \ManiaLive\DedicatedApi\Structures\ServerOptions
	 */
	public $server;
	/**
	 * @var \ManiaLive\DedicatedApi\Structures\GameInfos
	 */
	public $gameInfos;
	/**
	 * @var \ManiaLive\DedicatedApi\Structures\Status
	 */
	public $serverStatus;
	/**
	 * @var string
	 */
	public $serverLogin;

	/**
	 * @return \ManiaLive\Data\Storage
	 */
	static function getInstance()
	{
		return parent::getInstance();
	}

	protected function __construct()
	{
		\ManiaLive\Event\Dispatcher::register(\ManiaLive\DedicatedApi\Callback\Event::getClass(), $this);
		\ManiaLive\Event\Dispatcher::register(\ManiaLive\Application\Event::getClass(), $this);
	}

	#region Implementation de l'applicationListener
	function onInit()
	{
		$connexion = Connection::getInstance();
		$this->serverStatus = $connexion->getStatus();

		$players = $connexion->getPlayerList(-1, 0);
		foreach ($players as $player)
		{
			try
			{
				$details = $connexion->getDetailedPlayerInfo($player->login);

				foreach ($details as $key => $value)
				{
					if($value)
					{
						$param = lcfirst($key);
						$player->$param = $value;
					}
				}

				if($player->spectatorStatus % 10 == 0)
				{
					$this->players[$player->login] = $player;
				}
				else
				{
					$this->spectators[$player->login] = $player;
				}
			}
			catch (\Exception $e) {}
		}

		$this->challenges = $connexion->getChallengeList(-1,0);
		$currentIndex = $connexion->getCurrentChallengeIndex();
		$nextIndex = $connexion->getNextChallengeIndex();
		$this->nextChallenge = $this->challenges[$nextIndex];
		if($currentIndex != -1)
		{
			$this->currentChallenge = $connexion->getCurrentChallengeInfo();
		}

		$this->server = $connexion->getServerOptions();
		$this->gameInfos = $connexion->getCurrentGameInfo();
		$this->serverLogin = $connexion->getMainServerPlayerInfo()->login;
	}

	function onRun() {}
	function onPreLoop() {}
	
	function onPostLoop()
	{
		foreach ($this->disconnetedPlayers as $key => $login)
		{
			if(array_key_exists($login, $this->spectators))
			{
				$this->spectators[$login] = null;
				unset($this->spectators[$login]);
			}
			elseif (array_key_exists($login, $this->players))
			{
				$this->players[$login] = null;
				unset($this->players[$login]);
			}
			unset($this->disconnetedPlayers[$key]);
		}
	}
	
	function onTerminate() {}
	#endRegion

	#region Implementation of DedicatedApi\Listener
	function onPlayerConnect($login, $isSpectator)
	{
		try
		{
			$playerInfos = Connection::getInstance()->getPlayerInfo($login, 1);
			$details = Connection::getInstance()->getDetailedPlayerInfo($login);
				
			foreach ($details as $key => $value)
			{
				if($value)
				{
					$param = lcfirst($key);
					$playerInfos->$param = $value;
				}
			}

			if($isSpectator)
			{
				$this->spectators[$login] = $playerInfos;
			}
			else
			{
				$this->players[$login] = $playerInfos;
			}
		}

		// if player can not be added to array, then we stop the onPlayerConnect event!
		catch (\Exception $e)
		{
			if ($e->getCode() == -1000 && $e->getMessage() == 'Login unknown.')
			{
				throw new SilentCriticalEventException($e->getMessage());
			}
			else
			{
				throw new CriticalEventException($e->getMessage());
			}
		}
	}

	function onPlayerDisconnect($login)
	{
		$this->disconnetedPlayers[] = $login;
		$this->getPlayerObject($login)->isConnected = false;

		foreach($this->ranking as $key => $player)
		{
			if($player->login == $login)
			{
				unset($this->ranking[$key]);
				$found = true;
			}
		}
	}

	function onPlayerChat($playerUid, $login, $text, $isRegistredCmd) {}
	function onPlayerManialinkPageAnswer($playerUid, $login, $answer) {}
	function onEcho($internal, $public) {}
	function onServerStart() {}
	function onServerStop() {}
	function onBeginRace($challenge)
	{
		$gameInfos = Connection::getInstance()->getCurrentGameInfo();
		if($gameInfos != $this->gameInfos)
		{
			foreach ($gameInfos as $key => $value)
			{
				$this->gameInfos->$key = $value;
			}
		}

		$serverOptions = Connection::getInstance()->getServerOptions();
		if($serverOptions != $this->server)
		{
			foreach ($serverOptions as $key => $value)
			{
				$this->server->$key = $value;
			}
		}
	}

	function onEndRace($rankings, $challenge)
	{
		$rankings = Player::fromArrayOfArray($rankings);
		$this->updateRanking($rankings);
	}

	function onBeginChallenge($challenge, $warmUp, $matchContinuation)
	{
		$this->currentChallenge = Challenge::fromArray($challenge);
	}

	function onEndChallenge($rankings, $challenge, $wasWarmUp, $matchContinuesOnNextChallenge, $restartChallenge)
	{
		if(!$wasWarmUp)
		{
			$rankings = Player::fromArrayOfArray($rankings);
			$this->updateRanking($rankings);
		}
	}

	function onBeginRound() {}

	function onEndRound()
	{
		// TODO find a better way to handle the -1000 "no race in progress" error ...
		try
		{
			if(count($this->players) || count($this->spectators))
			{
				$rankings = Connection::getInstance()->getCurrentRanking(-1, 0);
				$this->updateRanking($rankings);
			}
		}
		catch (\Exception $ex) {}
	}

	function onStatusChanged($statusCode, $statusName)
	{
		$this->serverStatus->code = $statusCode;
		$this->serverStatus->name = $statusName;
	}

	function onPlayerCheckpoint($playerUid, $login, $timeOrScore, $curLap, $checkpointIndex) {}

	function onPlayerFinish($playerUid, $login, $timeOrScore)
	{
		if (!isset($this->players[$login])) return;
		$player = $this->players[$login];

		switch ($this->gameInfos->gameMode)
		{
			case Connection::GAMEMODE_STUNTS:
				if (($timeOrScore > $player->score || $player->score <= 0) && $timeOrScore > 0)
				{
					$old_score = $player->score;
					$player->score = $timeOrScore;
						
					$rankings = Connection::getInstance()->getCurrentRanking(-1, 0);
					$this->updateRanking($rankings);
						
					if ($player->score == $timeOrScore)
					{
						Dispatcher::dispatch(new Event($this, Event::ON_PLAYER_NEW_BEST_SCORE, array($player, $old_score, $timeOrScore)));
					}
				}
				break;

			default:
				if (($timeOrScore < $player->bestTime || $player->bestTime <= 0) && $timeOrScore > 0)
				{
					$old_best = $player->bestTime;
					$player->bestTime = $timeOrScore;
						
					$rankings = Connection::getInstance()->getCurrentRanking(-1, 0);
					$this->updateRanking($rankings);
						
					if ($player->bestTime == $timeOrScore)
					{
						Dispatcher::dispatch(new Event($this, Event::ON_PLAYER_NEW_BEST_TIME, array($player, $old_best, $timeOrScore)));
					}
				}
				break;
		}
	}

	function onPlayerIncoherence($playerUid, $login) {}
	function onBillUpdated($billId, $state, $stateName, $transactionId) {}
	function onTunnelDataReceived($playerUid, $login, $data) {}

	function onChallengeListModified($curChallengeIndex, $nextChallengeIndex, $isListModified)
	{
		if($isListModified)
		{
			$challenges = Connection::getInstance()->getChallengeList(-1, 0);

			foreach ($challenges as $key => $challenge)
			{
				$storageKey = array_search($challenge, $this->challenges);
				if(in_array($challenge, $this->challenges))
				{
					$challenges[$key] = $this->challenges[$storageKey];
				}
				else
				{
					$this->challenges[$storageKey] = null;
				}
			}
			$this->challenges = $challenges;
		}
		$this->nextChallenge = (array_key_exists($nextChallengeIndex, $this->challenges) ? $this->challenges[$nextChallengeIndex] : null);
	}

	function onPlayerInfoChanged($playerInfo)
	{
		
		$playerInfo = Player::fromArray($playerInfo);
		if($playerInfo->spectator == 0)
		{
			if(array_key_exists($playerInfo->login, $this->players))
			{
				foreach ($playerInfo as $key => $info)
				{
					$key = lcfirst($key);
					$this->players[$playerInfo->login]->$key = $info;
				}
			}
			elseif(array_key_exists($playerInfo->login, $this->spectators))
			{
				$this->players[$playerInfo->login] = $this->spectators[$playerInfo->login];

				unset($this->spectators[$playerInfo->login]);

				foreach ($playerInfo as $key => $info)
				{
					$key = lcfirst($key);
					$this->players[$playerInfo->login]->$key = $info;
				}
				Dispatcher::dispatch(new Event($this, Event::ON_PLAYER_CHANGE_SIDE, array($playerInfo, 'spectator')));
			}
		}
		else
		{
			if(array_key_exists($playerInfo->login, $this->spectators))
			{
				foreach ($playerInfo as $key => $info)
				{
					$key = lcfirst($key);
					$this->spectators[$playerInfo->login]->$key = $info;
				}
			}
			elseif(array_key_exists($playerInfo->login, $this->players))
			{
				$this->spectators[$playerInfo->login] = $this->players[$playerInfo->login];

				unset($this->players[$playerInfo->login]);

				foreach ($playerInfo as $key => $info)
				{
					$key = lcfirst($key);
					$this->spectators[$playerInfo->login]->$key = $info;
				}
				Dispatcher::dispatch(new Event($this, Event::ON_PLAYER_CHANGE_SIDE, array($playerInfo, 'player')));
			}
		}
		unset($playerInfo);
	}
	
	function onManualFlowControlTransition($transition) {}
	#endRegion

	/**
	 * Give a Player Object for the corresponding login
	 * @param string $login
	 * @return \ManiaLive\DedicatedApi\Structures\Player
	 */
	function getPlayerObject($login)
	{
		if(array_key_exists($login, $this->players))
		{
			return $this->players[$login];
		}
		elseif(array_key_exists($login, $this->spectators))
		{
			return $this->spectators[$login];
		}
		else
		return null;
	}

	protected function updateRanking($rankings)
	{

		$changed = array();
		foreach($rankings as $ranking)
		{
			if($ranking->rank == 0)
			{
				continue;
			}
			elseif(array_key_exists($ranking->login, $this->players))
			{
				$player = $this->players[$ranking->login];
				$rank_old = $player->rank;

				$player->playerId = $ranking->playerId;
				$player->rank = $ranking->rank;
				$player->bestTime = $ranking->bestTime;
				$player->bestCheckpoints = $ranking->bestCheckpoints;
				$player->score = $ranking->score;
				$player->nbrLapsFinished = $ranking->nbrLapsFinished;
				$player->ladderScore = $ranking->ladderScore;

				if ($rank_old != $player->rank)
				{
					Dispatcher::dispatch(new Event($this, Event::ON_PLAYER_NEW_RANK, array($player, $rank_old, $player->rank)));
				}

				$this->ranking[$ranking->rank] = $this->players[$ranking->login];
			}
			elseif (array_key_exists($ranking->login, $this->spectators))
			{
				$spectator = $this->spectators[$ranking->login];

				$spectator->playerId = $ranking->playerId;
				$spectator->rank = $ranking->rank;
				$spectator->bestTime = $ranking->bestTime;
				$spectator->bestCheckpoints = $ranking->bestCheckpoints;
				$spectator->score = $ranking->score;
				$spectator->nbrLapsFinished = $ranking->nbrLapsFinished;
				$spectator->ladderScore = $ranking->ladderScore;

				$this->ranking[$ranking->rank] = $this->spectators[$ranking->login];
			}
		}
	}
}
?>