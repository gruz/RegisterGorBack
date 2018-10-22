<?php
/**
 * RegisterGoBack plugin
 *
 * @package    RegisterGoBack
 *
 * @author     Gruz <arygroup@gmail.com>
 * @copyright  Copyleft (є) 2018 - All rights reversed
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace PlgSystemRegistergoback;

// No direct access
defined('_JEXEC') or die;

use \Joomla\CMS\Factory as JFactory;
use \Joomla\CMS\Table\Table as JTable;

/**
 * RegisterGoBack plugin
 *
 * @author  Gruz <arygroup@gmail.com>
 * @since   1.0.7
 */

class PlgSystemRegistergoback extends \JPlugin
{
	// Move non-Joomla API plugin functions to a separate file for easier code surfing.
	use Traits\Helper;

	/**
	 * Just to be sure not to confuse with any other extension
	 *
	 * @var string
	 */
	private $sessionNamespace = 'N;.pJ8"w';

	/**
	 * A list of components not to save to session on surfing.
	 *
	 * E.g. when we open an articles and proceed to register to gain access to it, we surf to com_users
	 * (or other components to be listed in the variable) and visit several com_users pages (e.g. be a registration page )
	 * We don't want to save such pages to our session.
	 *
	 * @var array
	 */
	private $treatAsUserComponents = ['com_users'];

	/**
	 * Components or components and specific tasks to be ignored by the pluign.
	 *
	 * If there are specific tasks to be ignored in conjunction with the component, then the tasks should be listed as the
	 * array values. Empty array means component should be ignored in general.
	 *
	 * @var array
	 */
	private $excludeOptions = [
		// We don't need to save anything at components, which don't generate pages at frontent.
		'com_dump' => [],
		'com_jcomments' => [],
		'com_jce' => [],
		'com_ajax' => [],

		// Specific situations
		'com_aicontactsafe' => ['captcha', 'message'],
		'com_rsfiles' => ['rsfiles.download'],
		'com_rsform' => ['ajaxValidate'],
	];

	/**
	 * Which pairs of option=>task are activation tasks.
	 *
	 * @var array
	 */
	private $activationComponents = [
		'com_users' => [
			'task' => 'registration.activate',
		],
		'com_rsform' => [
			'action' => 'user.activate',
		],
	];

	/**
	 * This event is triggered after the framework has loaded and initialised and the router has route the client request.
	 *
	 * Here in we try to save to session the current page link as previous link to be able later to go back to it.
	 *
	 * @return  boolean
	 */
	public function onAfterRoute()
	{
		// ~ Some basic variables to work
		$app	= JFactory::getApplication();
		$jinput = $app->input;
		$option = $jinput->get('option');
		$task = $jinput->get('task');
		$session = JFactory::getSession();

		// ~ We do not need to do anything, if the user is logged in
		if (!JFactory::getUser()->guest || $app->isAdmin())
		{
			return true;
		}

		// ~ If current link is the link to activate a user, then we run our custom function to activate and login user
		if ($this->isActivationExecuting())
		{
			$this->activateAndLoginUser();

			return true;
		}

		if (! $this->shouldProcessRequest())
		{
			return true;
		}

		$linkTreatedAsCurrent = $this->getUrlTreatedAsPrevious();

		// ~ Just store current link to session
		$session->set('lastLink', $linkTreatedAsCurrent, $this->sessionNamespace);

		return true;
	}

	/**
	 * Add the redirect link to the user params to be used once. Don't fire in backend and if not a new user creating.
	 *
	 * @param   array  $data    User data
	 * @param   bool   $isNew   If new user
	 * @param   mixed  $result  Result (don't know what it is)
	 * @param   mixed  $error   Error (don't nadle ut')
	 *
	 * @return   boolean  Always true
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
		$redirectUrl = $session->get('lastLink', null, $this->sessionNamespace);
		$session->clear('lastLink', $this->sessionNamespace);

		$params = json_decode($data['params']);
		$params->returnLink = $redirectUrl;

		$table = JTable::getInstance('user', 'JTable');
		$table->load($data['id']);
		$table->params = json_encode($params);
		$table->store();
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
			$origPass = $session->get('origPass', false, $this->sessionNamespace);

			if (false !== $origPass)
			{
				// ~ Set original user password into the HUser object
				$session->clear('origPass', $this->sessionNamespace);
				$user->password = $origPass;
			}
		}

		// ~ Get redirect link from either POST/GET/QUERY (lower priority)
		// ~ or from the user params (higher priority). Flush the user parameter

		// ~ So we use either the passed in some way redirect link, or
		// ~ use the stored in the user DB record returnLink
		$jinput = JFactory::getApplication()->input;
		$redirectUrl = $user->getParam('returnLink', null);
		$user->setParam('returnLink', null);

		if (!$redirectUrl)
		{
			$redirectUrl = $session->get('lastLink', false, $this->sessionNamespace);

			if (!$redirectUrl)
			{
				$redirectUrl = $jinput->get('return', false);
				$redirectUrl = base64_decode($redirectUrl);
			}
		}

		if (!empty($redirectUrl))
		{
			// ~ Set the redirect link to the users.login.form.return which will ab automatically used by Joomla
			// ~ ALAS redirect here doesn't work in this way.
			$app->setUserState('users.login.form.return', $redirectUrl);

			// ~ So we must redirect in a hardcore way:

			// ~ $app->redirect(JRoute::_($redirectUrl, false));
			// ~ $app->redirect(JRoute::_($app->getUserState('users.login.form.return'), false));
		}

		$user->save();

		return true;
	}
}
