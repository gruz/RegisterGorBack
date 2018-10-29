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

// No direct access
defined('_JEXEC') or die;

\JLoader::registerNamespace('PlgSystemRegistergoback', __DIR__ . '/src', false, false, 'psr4');

jimport('joomla.event.plugin');
jimport('joomla.user.user');

/**
 * RegisterGoBack plugin
 *
 * To use namespaced code here we extend our `entry point` class.
 *
 * @author  Gruz <arygroup@gmail.com>
 * @since   0.0.1
 */
class PlgSystemRegistergoback extends PlgSystemRegistergoback\PlgSystemRegistergoback
{
}
