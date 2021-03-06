<?php

/**
 * ProcessWire Session
 *
 * Start a session with login/logout capability 
 *
 * This should be used instead of the $_SESSION superglobal, though the $_SESSION superglobal can still be 
 * used, but it's in a different namespace than this. A value set in $_SESSION won't appear in $session
 * and likewise a value set in $session won't appear in $_SESSION.  It's also good to use this class
 * over the $_SESSION superglobal just in case we ever need to replace PHP's session handling in the future.
 * 
 * ProcessWire 2.x 
 * Copyright (C) 2010 by Ryan Cramer 
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 * 
 * http://www.processwire.com
 * http://www.ryancramer.com
 *
 */

class Session extends Wire implements IteratorAggregate {

	/**
	 * Reference to ProcessWire $config object
	 *
 	 * For convenience, since our __get() does not reference the Fuel, unlike other Wire derived classes.
	 *
	 */
	protected $config; 

	/**
	 * Start the session and set the current User if a session is active
	 *
	 * Assumes that you have already performed all session-specific ini_set() and session_name() calls 
	 *
	 */
	public function __construct() {

		$this->config = $this->fuel('config'); 
		@session_start();
		unregisterGLOBALS();
		$className = $this->className();

		if(empty($_SESSION[$className])) $_SESSION[$className] = array();

		if($userID = $this->get('_user_id')) {
			if($this->isValidSession()) {
				$user = $this->fuel('users')->get($userID); 
				if($user) $this->fuel('users')->setCurrentUser($user); 
			} else {
				$this->logout();
			}
		}

		foreach(array('message', 'error') as $type) {
			if($items = $this->get($type)) foreach($items as $text) parent::$type($text); 
			$this->remove($type);
		}

		$this->setTrackChanges(true);
	}

	/**
	 * Checks if the session is valid based on a challenge cookie and fingerprint
	 *
	 * These items may be disabled at the config level, in which case this method always returns true
 	 *
	 * @return bool
	 *
	 */
	protected function isValidSession() {

		$valid = true; 
		$sessionName = session_name();

		if($this->config->sessionChallenge) {
			if(empty($_COOKIE[$sessionName . "_challenge"]) || ($this->get('_user_challenge') != $_COOKIE[$sessionName . "_challenge"])) {
				$valid = false; 
			}
		}	

		if($this->config->sessionFingerprint) {
			if(($_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT']) != $this->get("_user_fingerprint")) {
				$valid = false; 
			}
		}

		return $valid; 
	}


	/**
	 * Get a session variable
	 *
	 * @param string $key
	 * @return mixed
	 *
	 */
	public function get($key) {
		$className = $this->className();
		return isset($_SESSION[$className][$key]) ? $_SESSION[$className][$key] : null; 
	}

	/**
	 * Get all session variables
 	 *
	 * @return array
	 *
	 */
	public function getAll() {
		return $_SESSION[$this->className()]; 
	}

	/**
	 * Set a session variable
	 *
	 * @param string $key
	 * @param mixed $value
 	 * @return this
	 *
	 */
	public function set($key, $value) {
		$className = $this->className();
		$oldValue = $this->get($key); 
		if($value !== $oldValue) $this->trackChange($key); 
		$_SESSION[$className][$key] = $value; 
		return $this; 
	}

	/**
	 * Unsets a session variable
	 *
 	 * @param string $value
	 * @return this
	 *
	 */
	public function remove($key) {
		unset($_SESSION[$this->className()][$key]); 
		return $this; 
	}

	/**
	 * Provide $session->variable get access
	 *
	 */
	public function __get($key) {
		return $this->get($key); 
	}

	/**
	 * Provide $session->variable = variable set access
	 *
	 */
	public function __set($key, $value) {
		return $this->set($key, $value); 
	}

	/**
	 * Allow iteration of session variables, i.e. foreach($session as $key => $var) {} 
	 *
	 */
	public function getIterator() {
		return new ArrayObject($_SESSION[$this->className()]); 
	}

	/**
	 * Login a user with the given name and password
	 *
	 * Also sets them to the current user
	 *
	 * @param string $name
	 * @param string $pass Raw, non-hashed password
	 * @return bool True if the login was successful, false if not
	 *
	 */
	public function login($name, $pass) {

		if(!$this->allowLogin($name)) return false; 

		if($user = $this->fuel('users')->authenticateUser($name, $pass)) {

			$this->trackChange('login'); 
			session_regenerate_id();
			$this->set('_user_id', $user->id); 

			if($this->config->sessionChallenge) {
				$challenge = md5(mt_rand() . $user->id . microtime()); 
				$expireSeconds = $this->config->sessionExpireSeconds ? time() + $this->config->sessionExpireSeconds : 0; 
				setcookie(session_name() . "_challenge", $challenge, $expireSeconds, '/', null, false, true); 
				$this->set('_user_challenge', $challenge); 
			}

			if($this->config->sessionFingerprint) {
				$this->set('_user_fingerprint', $_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT']); 
			}

			return true; 
		}

		
		return false; 
	}

	/**
	 * Return true or false whether login is currently allowed 
	 *
	 * This is so that modules can dictate the functionality for this method
	 *
	 * @param string $name Username
	 * @return bool
	 *
	 */
	public function ___allowLogin($name) {
		return true; 
	}

	/**
	 * Logout the current user, and clear all session variables
	 *
	 * @return this
	 *
	 */
	public function logout() {
		$sessionName = session_name();
		$_SESSION = array();
		if(isset($_COOKIE[$sessionName])) setcookie($sessionName, '', time()-42000, '/'); 
		if(isset($_COOKIE[$sessionName . "_challenge"])) setcookie($sessionName . "_challenge", '', time()-42000, '/'); 
		session_destroy();
		session_name($sessionName); 
		session_start(); 
		session_regenerate_id();
		$_SESSION[$this->className()] = array();
		$this->fuel('users')->setCurrentUserGuest(); 
		$this->trackChange('logout'); 
		return $this; 
	}

	/**
	 * Redirect this session to another URL
	 *
	 */
	public function redirect($url, $http301 = true) {

		// if there are notices, then queue them so that they aren't lost
		$notices = $this->fuel('notices'); 
		if(count($notices)) foreach($notices as $notice) {
			$this->queueNotice($notice->text, $notice instanceof NoticeError ? 'error' : 'message'); 
		}

		// perform the redirect
		if($http301) header("HTTP/1.1 301 Moved Permanently");
		header("Location: $url");
		header("Connection: close"); 
		exit(0);
	}

	/**
	 * Queue a notice (message/error) to be shown the next time this ession class is instantiated
	 *
	 */
	protected function queueNotice($text, $type) {
		$items = $this->get($type);
		if(is_null($items)) $items = array();
		$items[] = $text; 
		$this->set($type, $items); 
	}

	/**
	 * Queue a message to appear the next time session is instantiated
	 *
	 */
	public function message($text) {
		$this->queueNotice($text, 'message'); 
		return $this;
	}

	/**
	 * Queue an error to appear the next time session is instantiated
	 *
	 */
	public function error($text) {
		$this->queueNotice($text, 'error'); 
		return $this; 
	}


}
