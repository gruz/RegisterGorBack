<?php
/**
 * Helper
 *
 * @package     RegisterGoBack
 *
 * @author      Gruz <arygroup@gmail.com>
 * @copyright   Copyleft (є) 2018 - All rights reversed
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace PlgSystemRegistergoback\Traits;

// No direct access
defined('_JEXEC') or die;

/**
 * A helper trait
 *
 * @since 1.0.6
 */
trait Helper
{
	/**
	 * Determines if the current request should be processed by the plugin
	 *
	 * @return boolean
	 */
	public function doNotProcessRequest()
	{
		if ('POST' === $_SERVER['REQUEST_METHOD'])
		{
			return false;
		}

		return $this->compareParamsAndRequest($this->excludeOptions);
	}

	/**
	 * Determines if the current page is a user registration page.
	 *
	 * The function usage in the code is indended, that if true, then we don't save the current page url as the previous link.
	 *
	 * @return boolean
	 */
	public function isAuthRelatedPage()
	{
		return $this->compareParamsAndRequest($this->treatAsUserComponents);
	}

	/**
	 * Compares current request with a quazi-request stored in $array.
	 *
	 * E.g. option=com_rsform&formId=5 should match
	 * 		$array[
	 * 			'com_rsform' => ['formId' => 5],
	 * 		]
	 * or
	 * 		$array[
	 * 			'com_rsform' => ['formId' => '*'],
	 * 		]
	 *
	 * @param   mixed  $array  A set of parameter which should match a request.
	 *
	 * @return boolean
	 */
	private function compareParamsAndRequest($array)
	{
		$jinput = \JFactory::getApplication()->input;
		$option = $jinput->get('option');

		if (! array_key_exists($option, $array))
		{
			return false;
		}

		if (empty($array[$option]))
		{
			return true;
		}

		foreach ($array[$option] as $key => $value)
		{
			if ('**' === $value) // $key can be even empty.
			{
				continue;
			}

			$action = $jinput->get($key, null);

			if ($action && '*' === $value)
			{
				continue;
			}

			if ($action == $value) // Not ===, because there can be '5' == 5
			{
				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * Determine if current request is an activation request.
	 *
	 * @return boolean
	 */
	public function isActivationExecuting()
	{
		// ~ Some basic variables to work
		$app	= \JFactory::getApplication();
		$jinput = $app->input;
		$option = $jinput->get('option');
		$task = $jinput->get('task');

		if (empty($this->activationComponents[$option]))
		{
			return false;
		}

		foreach ($this->activationComponents[$option] as $key => $value)
		{
			$action = $jinput->get($key, null);

			if ($action !== $value)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Determine, which link to treat as the current one( a candidate to be the return link after registration).
	 *
	 * E.g. when we click at an article and need to be authorized to read it, we are redirected to com_users to registrer.
	 * We must store the article link to go back when logged in, not any of the com_users pages.
	 *
	 * If we are at a com_users page, then we assume we have come here from somewhere ( $prevLink ).
	 * So we don't use the current url to save, but just get previously (before com_users) stored.
	 *
	 * @return boolean
	 */
	public function getUrlTreatedAsPrevious()
	{
		$session = \JFactory::getSession();
		$app	= \JFactory::getApplication();
		$jinput = $app->input;

		$urlCandidateToBeReturnAfterRegistration = $jinput->get('return', false);

		if ($this->isAuthRelatedPage())
		{
			$urlCandidateToBeReturnAfterRegistration = $session->get('lastLink', '', $this->sessionNamespace);
		}
		// If there is a `return` value ( passed by com_users as base64 within url ), then decode it and use as the link to return.
		elseif ($urlCandidateToBeReturnAfterRegistration)
		{
			$urlCandidateToBeReturnAfterRegistration = base64_decode($urlCandidateToBeReturnAfterRegistration);
		}
		// Here we build current link from Joomla API. I dont't remember why, maybe to avoid sef problems,
		// we save the link in the internal link representation like `index.php?option=com_rsfiles&task=rsfiles.download`
		else
		{
			/*
			// Here I "unparse" the Joomla SEF url to get the internal joomla URL
			JURI::current();// ~ It's very strange, but without this line at least Joomla 3 fails to fulfill the task
			$router = JSite::getRouter();// ~ get router
			$juri = JURI::getInstance();
			$query = $router->parse($juri); // ~ Get the real joomla query as an array - parse current joomla link
			$urlCandidateToBeReturnAfterRegistration = 'index.php?'.JURI::getInstance()->buildQuery($query);
			*/

			// ~ build the JInput object
			$jinput = \JFactory::getApplication()->input;

			// ~ retrieve the array of values from the request (stored in the application environment) to form the query
			$uriQuery = $jinput->getArray();

			$urlCandidateToBeReturnAfterRegistration = 'index.php?' . \JUri::buildQuery($uriQuery);
		}

		return $urlCandidateToBeReturnAfterRegistration;
	}


	/**
	 * Activates the users by email link or by manually entered token. Logins user.
	 *
	 * We are here, if we click a registration activation link or enter the activation token manually
	 * We cannot catch the moment when a user is activated by Joomla (there is no event like onUserActivate).
	 * So to login user on activation complete we activete the user manually and login one.
	 * To login we must know the user password, which is not possible. So before login we store the existing
	 * password hash to the session, set a new temorary password, use the new password to login.
	 * Later, onUserLogin, we restore the original password hash
	 *
	 * @return   mixed  bool or void
	 */
	public function activateAndLoginUser()
	{
		if (!$this->params->get('autologinonactivate', 1))
		{
			return null;
		}

		$app	= \JFactory::getApplication();
		$jinput = $app->input;

		// $option = $jinput->get('option');
		// $task = $jinput->get('task');

		// ~ Get com_users options
		$uParams	= \JComponentHelper::getParams('com_users');

		// ~ If user registration or account activation is disabled, throw a 403.
		if ($uParams->get('useractivation') == 0 || $uParams->get('allowUserRegistration') == 0)
		{
			\JError::raiseError(403, \JText::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'));

			return false;
		}

		// ~ Get the activation token
		$token = $jinput->get('token', null, 'request', 'alnum');

		// ~ Check that the token is in a valid format.
		if ($token === null || strlen($token) !== 32)
		{
			\JError::raiseError(403, \JText::_('JINVALID_TOKEN'));

			return false;
		}

		/*
		Get the user which has the current token form DB to
		temporary store one's password. We need this to perform
		autologin. We cannot decode the existing password, so we
		use a temporary known password, login and then after
		login restore the preserved password directly in the
		database
		*/
		$db		= \JFactory::getDBO();
		$query	= $db->getQuery(true);
		$query->select('id,username, email, password');
		$query->from('#__users');
		$query->where('activation=' . $db->Quote($token));
		$db->setQuery($query);
		$result = $db->loadObject();

		if (empty($result))
		{
			return true;
		}

		// ~ Preserve original password
		$session = \JFactory::getSession();
		$session->set('origPass', $result->password, $this->sessionNamespace);

		// ~ Set a temporary password for the user
		$tempPass = \JApplicationHelper::getHash(\JUserHelper::genRandomPassword());

		$query	= $db->getQuery(true);
		$query->update('#__users');
		$query->set('password = ' . $db->Quote(md5($tempPass)));
		$query->where('id=' . $db->Quote($result->id));
		$db->setquery($query);
		$db->execute();

		// ~ $getaffectedrows = $db->getAffectedRows();
		// ~ dump ($getaffectedrows,'$res');

		// ~ $db->query($query);

		// ~ Attempt to activate the user.
		// ~ First we import some help from the joomla framework
		\JLoader::import('joomla.application.component.model');

		// ~ Load and get com_users's model
		\JLoader::import('registration', JPATH_BASE . '/components/com_users/models');
		$model = \JModelLegacy::getInstance('Registration', 'UsersModel');

		$return = $model->activate($token);

		// ~ Check for errors. If couldn't activate, then return error message
		if ($return === false)
		{
			// ~ Redirect back to the homepage.
			// ~ $app->redirect("",\JText::sprintf('COM_USERS_REGISTRATION_SAVE_FAILED', $model->getError()),'warning');
			return true;
		}

		// ~ If activation is enabled and we are here if we could activate the account,
		// ~ then perform the login after login restore the password and redirect

		if ($uParams->get('useractivation') == 1)
		{
			$credentials = array('username' => $result->username, 'password' => $tempPass);
			$result = $app->login($credentials);
			$redirectUrl = $app->getUserState('users.login.form.return', 'index.php');

			// Do not delete! May be reused later. Leave here as an example.
			// $redirectUrl = $this->alterRedirectLink($redirectUrl);

			$redirectUrl = \Joomla\CMS\Router\Route::_($redirectUrl, false);
			$app->redirect($redirectUrl);
		}

		return true;
	}

	/**
	 * We may need to alter redirect link as pre-login and post-login links can differ.
	 *
	 * E.g. com_rsfiles dowload links are different when a guest and a refistered user sees it.
	 * Currently // ##mygruz20181022110257  is disabled as not needed. May be reused.
	 *
	 * @param   string $redirectUrl Redirect link.
	 *
	 * @return string
	 */
	private function alterRedirectLink(string $redirectUrl)
	{
		$parsedUrl = parse_url($redirectUrl);
		parse_str($parsedUrl['query'], $uriQuery);

		$paramSets = [
			[
				'from' => [
					'option' => 'com_rsfiles',
					'layout' => 'download',
					'path' => '*',
				],
				'to' => [
					'option' => 'com_rsfiles',
					'task' => 'rsfiles.download',
					'path' => '*',
				]
			]
		];

		foreach ($paramSets as $paramSet)
		{
			$paramSetMatch = true;

			foreach ($paramSet['from'] as $key => $value)
			{
				if (empty($uriQuery[$key]))
				{
					$paramSetMatch = false;
					break;
				}

				if ('*' !== $value && $value !== $uriQuery[$key])
				{
					$paramSetMatch = false;
					break;
				}
			}

			if (! $paramSetMatch)
			{
				continue;
			}

			$newUriQuery = [];

			foreach ($paramSet['to'] as $key => $value)
			{
				if ('*' !== $value)
				{
					$newUriQuery[$key] = $value;
				}
				else
				{
					$newUriQuery[$key] = $uriQuery[$key];
				}
			}

			$uriQuery = $newUriQuery;
			break;
		}

		$redirectUrl = 'index.php?' . \JUri::buildQuery($uriQuery);

		return $redirectUrl;
	}
}
