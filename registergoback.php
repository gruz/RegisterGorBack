<?php
/**
 * RegisterGoBack plugin
 *
 * @package    RegisterGoBack
 *
 * @author     Gruz <arygroup@gmail.com>
 * @copyright  Copyleft (є) 2016 - All rights reversed
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

// ~ No direct access
defined('_JEXEC') or die('Restricted access');


jimport('joomla.event.plugin');
jimport('joomla.user.user');

/**
 * RegisterGoBack plugin
 *
 * @author  Gruz <arygroup@gmail.com>
 * @since   0.0.1
 */
class PlgSystemRegistergoback extends JPlugin
{
	// ~ Just to be sure not to confuse with any other extension
	private $session_namespace = 'N;.pJ8"w';

	private $component = 'com_users';

	/**
	 * This event is triggered after the framework has loaded and initialised and the router has route the client request.
	 *
	 * Routing is the process of examining the request environment to determine
	 * which component should receive the request.
	 * This component optional parameters are then set in the request object
	 * to be processed when the application is being dispatched.
	 * When this event triggers the router has parsed the route and
	 * pushed the request parameters into JRequest for retrieval by the application.
	 *
	 * @return  void
	 */
	public function onAfterRoute()
	{
		$options_to_exclude = array('com_dump', 'com_jcomments', 'com_jce');

		// ~ Some basic variables to work
		$app	= JFactory::getApplication();
		$jinput = $app->input;
		$option = $jinput->get('option');
		$task = $jinput->get('task');

		if (in_array($option, $options_to_exclude)  )
		{
			return true;
		}

		if ($option == 'com_aicontactsafe' && in_array($task, array('captcha', 'message')))
		{
			return true;
		}

		// ~ We do not need to do anything, if the user is logged in
		if (!JFactory::getUser()->guest || $app->isAdmin())
		{
			return true;
		}

		$session = JFactory::getSession();

		/*
		If the user is a guest, then we must store current link to Session each time
		one follows a web-site link. So at the moment, when the users clicks on "Register"
		OR(maybe) login, we fixate the last link as the returnLink

		Перевіряємо, чи відбувся перехід з не com_users на com_users.
		Якщо відбувся, то попередній лінк фіксуємо як returnLink.
		А для цього коже раз ще зберігаємо поточний компонент у сесію,
		щоби наступного разу порівняти його із вже новим поточним.
		Якщо не відбувся, то попередній лінк затираємо поточним.
		*/

		$prev_component = $session->get('previous_component', null, $this->session_namespace);

		$session->set('previous_component', $option, $this->session_namespace);

		// ~ if ($option == $this->component && $prev_component == $this->component ) { return true; }

		$prev_link = $session->get('previous_link', '', $this->session_namespace);

		$url = $jinput->get('return', false);

		if ($option == $this->component)
		{
			$url = $prev_link;
		}
		elseif ($url)
		{
			$url = base64_decode($url);
		}
		else
		{
			/* 	Here I "unparse" the Joomla SEF url to get the internal joomla URL
			JURI::current();// ~ It's very strange, but without this line at least Joomla 3 fails to fulfill the task
			$router = JSite::getRouter();// ~ get router
			$juri = JURI::getInstance();
			$query = $router->parse($juri); // ~ Get the real joomla query as an array - parse current joomla link
			$url = 'index.php?'.JURI::getInstance()->buildQuery($query);
			*/

			// ~ build the JInput object
			$jinput = JFactory::getApplication()->input;

			// ~ retrieve the array of values from the request (stored in the application environment) to form the query
			$uriQuery = $jinput->getArray();

			// ~ build the the query as a string
			$url = 'index.php?' . JUri::buildQuery($uriQuery);
		}
		// ~ Just store current link to session to use it
		$session->set('previous_link', $url, $this->session_namespace);

		// ~ If current link is the link to activate a user, then we run our custom functio to activate and login user
		if ($option == $this->component && $task == 'registration.activate')
		{
			$this->activateAndLoginUser();

			return true;
		}

		if (true || $option == $this->component && $prev_component != $this->component )
		{
			// ~ Get $return value as explained here http://docs.joomla.org/How_do_you_redirect_users_after_a_successful_login%3F
			$return = $jinput->get('return', false);

			// ~ If there is such a return value passed, the we decode it and store to the session as returnLink.

			// ~ So we store the initial return link into the session and have in in the Session while "surfing" registration process.
			// ~ When registration process is finished, the link is stored to the newly user to be used for redirect.
			if ($return)
			{
				$return = base64_decode($jinput->get('return'));
				$session = JFactory::getSession();
				$session->set('returnLink', $return, $this->session_namespace);
			}
			else
			{
				$session->set('returnLink', $prev_link, $this->session_namespace);

				// ~ $session->clear('previous_link', $this->session_namespace);
			}
		}

		return true;
	}

	/**
	 * Add the redirect link to the user params to be used once
	 *
	 * @param   array  $data    User data
	 * @param   bool   $isNew   If new user
	 * @param   mixed  $result  Result (don't know what it is)
	 * @param   mixed  $error   Error (don't nadle ut')
	 *
	 * @return   bool  Always true
	 */
	public function onUserAfterSave($data, $isNew, $result, $error)
	{
		// ~ Some basic variables to work
		$app	= JFactory::getApplication();

		// ~ We do not need to do anything, if the user is logged in
		if (!JFactory::getUser()->guest || $app->isAdmin() || !$isNew)
		{
			return true;
		}

		$session = JFactory::getSession();
		$redirect_url = $session->get('returnLink', null, $this->session_namespace);
		$session->clear('returnLink', $this->session_namespace);

		$params = json_decode($data['params']);
		$params->returnLink = $redirect_url;

		$table = JTable::getInstance('user', 'JTable');
		$table->load($data['id']);
		$table->params = json_encode($params);
		$table->store();
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
	 * @return   mixd  bool or void
	 */
	public function activateAndLoginUser()
	{
		if (!$this->params->get('autologinonactivate', 1))
		{
			return;
		}

		$app	= JFactory::getApplication();
		$jinput = $app->input;

		if ($jinput->get('option') != $this->component && $jinput->get('task') != 'registration.activate' )
		{
			return false;
		}

		// ~ Get com_users options
		$uParams	= JComponentHelper::getParams($this->component);

		// ~ If user registration or account activation is disabled, throw a 403.
		if ($uParams->get('useractivation') == 0 || $uParams->get('allowUserRegistration') == 0)
		{
			JError::raiseError(403, JText::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'));

			return false;
		}

		// ~ Get the activation token
		$token = $jinput->get('token', null, 'request', 'alnum');

		// ~ Check that the token is in a valid format.
		if ($token === null || strlen($token) !== 32)
		{
			JError::raiseError(403, JText::_('JINVALID_TOKEN'));

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

		$db		= JFactory::getDBO();
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
		$session = JFactory::getSession();
		$session->set('origPass', $result->password, $this->session_namespace);

		// ~ Set a temporary password for the user
		$temp_pass = JApplicationHelper::getHash(JUserHelper::genRandomPassword());

		$query	= $db->getQuery(true);
		$query->update('#__users');
		$query->set('password = ' . $db->Quote(md5($temp_pass)));
		$query->where('id=' . $db->Quote($result->id));
		$db->setquery($query);
		$db->execute();

		// ~ $getaffectedrows = $db->getAffectedRows();
// ~ dump ($getaffectedrows,'$res');

		// ~ $db->query($query);

		// ~ Attempt to activate the user.
		// ~ First we import some help from the joomla framework
		JLoader::import('joomla.application.component.model');

		// ~ Load and get com_users's model
		JLoader::import('registration', JPATH_BASE . '/components/com_users/models');
		$model = JModelLegacy::getInstance('Registration', 'UsersModel');

		$return = $model->activate($token);

		// ~ Check for errors. If couldn't activate, then return error message
		if ($return === false)
		{
			// ~ Redirect back to the homepage.
			// ~ $app->redirect("",JText::sprintf('COM_USERS_REGISTRATION_SAVE_FAILED', $model->getError()),'warning');
			return true;
		}

		// ~ If activation is enabled and we are here if we could activate the account,
		// ~ then perform the login after login restore the password and redirect

		if ($uParams->get('useractivation') == 1)
		{
			$credentials = array('username' => $result->username, 'password' => $temp_pass);
			$result = $app->login($credentials);
			$redirect_url = $app->getUserState('users.login.form.return', 'index.php');
			$app->redirect($redirect_url);
		}

		return true;
	}

	/**
	 * If needed restore original password and redirect
	 *
	 * @param   array  $userarr  User data
	 * @param   mixed  $options  Options
	 *
	 * @return   mixed  Null or true
	 */
	public function onUserLogin($userarr, $options)
	{
		$app	= JFactory::getApplication();

		// ~ We do not need to do anything, if the user is logged in
		if (!JFactory::getUser()->guest || $app->isAdmin())
		{
			return true;
		}

		$db		= JFactory::getDbo();
		$query	= $db->getQuery(true);

		// ~ Get regular joomla user object via getting user id via DB query
		$query->select('id');
		$query->from('#__users');
		$query->where('username=' . $db->Quote($userarr['username']));

		$db->setQuery($query);
		$result = $db->loadObject();
		$user = JFactory::getUser($result->id);

		$session = JFactory::getSession();
		if ($this->params->get('autologinonactivate', 1))
		{
			// ~ Restore original password previously stored in session

			// ~ Get previously stored user password
			$origPass = $session->get('origPass', false, $this->session_namespace);

			if ($origPass === false )
			{
				// ~ return true;
			}
			else
			{
				// ~ Set original user password into the HUser object
				$session->clear('origPass', $this->session_namespace);
				$user->password = $origPass;
			}
		}

		// ~ Get redirect link from either POST/GET/QUERY (lower priority)
		// ~ or from the user params (higher priority). Flush the user parameter

		// ~ So we use either the passed in some way redirect link, or
		// ~ use the stored in the user DB record returnLink
		$jinput = JFactory::getApplication()->input;
		$redirect_url = $user->getParam('returnLink', null);
		$user->setParam('returnLink', null);

		if (!$redirect_url)
		{
			$redirect_url = $session->get('previous_link', false, $this->session_namespace);
			if (!$redirect_url)
			{
				$redirect_url = $jinput->get('return', false);
				$redirect_url = base64_decode($redirect_url);
			}
		}

		if (!empty ($redirect_url))
		{
			// ~ Set the redirect link to the users.login.form.return which will ab automatically used by Joomla
			// ~ ALAS redirect here doesn't work in this way.
			$app->setUserState('users.login.form.return', $redirect_url);

			// ~ So we must redirect in a hardcore way:

			// ~ $app->redirect(JRoute::_($redirect_url, false));
			// ~ $app->redirect(JRoute::_($app->getUserState('users.login.form.return'), false));
		}

		$user->save();

		return true;
	}
}
