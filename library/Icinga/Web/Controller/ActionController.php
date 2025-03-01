<?php
/* Icinga Web 2 | (c) 2013 Icinga Development Team | GPLv2+ */

namespace Icinga\Web\Controller;

use Icinga\Application\Modules\Module;
use Icinga\Common\PdfExport;
use Icinga\File\Pdf;
use ipl\I18n\Translation;
use Zend_Controller_Action;
use Zend_Controller_Action_HelperBroker;
use Zend_Controller_Request_Abstract;
use Zend_Controller_Response_Abstract;
use Icinga\Application\Benchmark;
use Icinga\Application\Config;
use Icinga\Authentication\Auth;
use Icinga\Exception\Http\HttpMethodNotAllowedException;
use Icinga\Exception\IcingaException;
use Icinga\Exception\ProgrammingError;
use Icinga\Forms\AutoRefreshForm;
use Icinga\Security\SecurityException;
use Icinga\Web\Session;
use Icinga\Web\Url;
use Icinga\Web\UrlParams;
use Icinga\Web\Widget\Tabs;
use Icinga\Web\Window;

/**
 * Base class for all core action controllers
 *
 * All Icinga Web core controllers should extend this class
 *
 * @method \Icinga\Web\Request getRequest() {
 *     {@inheritdoc}
 *     @return  \Icinga\Web\Request
 * }
 *
 * @method \Icinga\Web\Response getResponse() {
 *     {@inheritdoc}
 *     @return  \Icinga\Web\Response
 * }
 */
class ActionController extends Zend_Controller_Action
{
    use Translation;
    use PdfExport {
        sendAsPdf as private newSendAsPdf;
    }

    /**
     * The login route to use when requiring authentication
     */
    const LOGIN_ROUTE = 'authentication/login';

    /**
     * The default page title to use
     */
    const DEFAULT_TITLE = 'Icinga Web';

    /**
     * Whether the controller requires the user to be authenticated
     *
     * @var bool
     */
    protected $requiresAuthentication = true;

    /**
     * The current module's name
     *
     * @var string
     */
    protected $moduleName;

    /**
     * A page's automatic refresh interval
     *
     * The initial value will not be subject to a user's preferences.
     *
     * @var int
     */
    protected $autorefreshInterval;

    protected $reloadCss = false;

    protected $window;

    protected $rerenderLayout = false;

    /**
     * The inline layout (inside columns) to use
     *
     * @var string
     */
    protected $inlineLayout = 'inline';

    /**
     * The inner layout (inside the body) to use
     *
     * @var string
     */
    protected $innerLayout = 'body';

    /**
     * Authentication manager
     *
     * @var Auth|null
     */
    protected $auth;

    /**
     * URL parameters
     *
     * @var UrlParams
     */
    protected $params;

    /**
     * The constructor starts benchmarking, loads the configuration and sets
     * other useful controller properties
     *
     * @param Zend_Controller_Request_Abstract  $request
     * @param Zend_Controller_Response_Abstract $response
     * @param array                             $invokeArgs Any additional invocation arguments
     */
    public function __construct(
        Zend_Controller_Request_Abstract $request,
        Zend_Controller_Response_Abstract $response,
        array $invokeArgs = array()
    ) {
        /** @var \Icinga\Web\Request $request */
        /** @var \Icinga\Web\Response $response */
        $this->params = UrlParams::fromQueryString();

        $this->setRequest($request)
            ->setResponse($response)
            ->_setInvokeArgs($invokeArgs);
        $this->_helper = new Zend_Controller_Action_HelperBroker($this);

        $moduleName = $this->getModuleName();
        $this->view->defaultTitle = static::DEFAULT_TITLE;
        $this->translationDomain = $moduleName !== 'default' ? $moduleName : 'icinga';
        $this->view->translationDomain = $this->translationDomain;
        $this->_helper->layout()->isIframe = $request->getUrl()->shift('isIframe');
        $this->_helper->layout()->showFullscreen = $request->getUrl()->shift('showFullscreen');
        $this->_helper->layout()->moduleName = $moduleName;

        $this->view->compact = false;
        if ($request->getUrl()->getParam('view') === 'compact') {
            $request->getUrl()->remove('view');
            $this->view->compact = true;
        }
        if ($request->getUrl()->shift('showCompact')) {
            $this->view->compact = true;
        }
        $this->rerenderLayout = $request->getUrl()->shift('renderLayout');
        if ($request->getUrl()->shift('_disableLayout')) {
            $this->_helper->layout()->disableLayout();
        }

        // $auth->authenticate($request, $response, $this->requiresLogin());
        if ($this->requiresLogin()) {
            if (! $request->isXmlHttpRequest() && $request->isApiRequest()) {
                Auth::getInstance()->challengeHttp();
            }
            $this->redirectToLogin(Url::fromRequest());
        }

        $this->view->tabs = new Tabs();
        $this->prepareInit();
        $this->init();
    }

    /**
     * Prepare controller initialization
     *
     * As it should not be required for controllers to call the parent's init() method, base controllers should use
     * prepareInit() in order to prepare the controller initialization.
     *
     * @see \Zend_Controller_Action::init() For the controller initialization method.
     */
    protected function prepareInit()
    {
    }

    /**
     * Get the authentication manager
     *
     * @return Auth
     */
    public function Auth()
    {
        if ($this->auth === null) {
            $this->auth = Auth::getInstance();
        }
        return $this->auth;
    }

    /**
     * Whether the current user has the given permission
     *
     * @param   string  $permission Name of the permission
     *
     * @return  bool
     */
    public function hasPermission($permission)
    {
        return $this->Auth()->hasPermission($permission);
    }

    /**
     * Assert that the current user has the given permission
     *
     * @param   string  $permission     Name of the permission
     *
     * @throws  SecurityException       If the current user lacks the given permission
     */
    public function assertPermission($permission)
    {
        if (! $this->Auth()->hasPermission($permission)) {
            throw new SecurityException('No permission for %s', $permission);
        }
    }

    /**
     * Return the current module's name
     *
     * @return  string
     */
    public function getModuleName()
    {
        if ($this->moduleName === null) {
            $this->moduleName = $this->getRequest()->getModuleName();
        }

        return $this->moduleName;
    }

    public function Config($file = null)
    {
        if ($file === null) {
            return Config::app();
        } else {
            return Config::app($file);
        }
    }

    public function Window()
    {
        if ($this->window === null) {
            $this->window = Window::getInstance();
        }

        return $this->window;
    }

    protected function reloadCss()
    {
        $this->reloadCss = true;
        return $this;
    }

    /**
     * Respond with HTTP 405 if the current request's method is not one of the given methods
     *
     * @param   string $httpMethod              Unlimited number of allowed HTTP methods
     *
     * @throws  HttpMethodNotAllowedException   If the request method is not one of the given methods
     */
    public function assertHttpMethod($httpMethod)
    {
        $httpMethods = array_flip(array_map('strtoupper', func_get_args()));
        if (! isset($httpMethods[$this->getRequest()->getMethod()])) {
            $e = new HttpMethodNotAllowedException($this->translate('Method Not Allowed'));
            $e->setAllowedMethods(implode(', ', array_keys($httpMethods)));
            throw $e;
        }
    }

    /**
     * Return restriction information for an eventually authenticated user
     *
     * @param   string  $name   Restriction name
     *
     * @return  array
     */
    public function getRestrictions($name)
    {
        return $this->Auth()->getRestrictions($name);
    }

    /**
     * Check whether the controller requires a login. That is when the controller requires authentication and the
     * user is currently not authenticated
     *
     * @return  bool
     */
    protected function requiresLogin()
    {
        if (! $this->requiresAuthentication) {
            return false;
        }

        return ! $this->Auth()->isAuthenticated();
    }

    /**
     * Return the tabs
     *
     * @return Tabs
     */
    public function getTabs()
    {
        return $this->view->tabs;
    }

    protected function ignoreXhrBody()
    {
        if ($this->isXhr()) {
            $this->getResponse()->setHeader('X-Icinga-Container', 'ignore');
        }
    }

    /**
     * Set the interval (in seconds) at which the page should automatically refresh
     *
     * This may be adjusted based on the user's preferences. The result could be a
     * lower or higher rate of the page's automatic refresh. If this is not desired,
     * the only way to bypass this is to initialize the {@see ActionController::$autorefreshInterval}
     * property or to set the `autorefreshInterval` property of the layout directly.
     *
     * @param int $interval
     *
     * @return $this
     */
    public function setAutorefreshInterval($interval)
    {
        if (! is_int($interval) || $interval < 1) {
            throw new ProgrammingError(
                'Setting autorefresh interval smaller than 1 second is not allowed'
            );
        }

        $user = $this->getRequest()->getUser();
        if ($user !== null) {
            $speed = (float) $user->getPreferences()->getValue('icingaweb', 'auto_refresh_speed', 1.0);
            $interval = max(round($interval * $speed), min($interval, 5));
        }

        $this->autorefreshInterval = $interval;

        return $this;
    }

    public function disableAutoRefresh()
    {
        $this->autorefreshInterval = null;

        return $this;
    }

    /**
     * Redirect to login
     *
     * XHR will always redirect to __SELF__ if an URL to redirect to after successful login is set. __SELF__ instructs
     * JavaScript to redirect to the current window's URL if it's an auto-refresh request or to redirect to the URL
     * which required login if it's not an auto-refreshing one.
     *
     * XHR will respond with HTTP status code 403 Forbidden.
     *
     * @param   Url|string  $redirect   URL to redirect to after successful login
     */
    protected function redirectToLogin($redirect = null)
    {
        $login = Url::fromPath(static::LOGIN_ROUTE);
        if ($this->isXhr()) {
            if ($redirect !== null) {
                $login->setParam('redirect', '__SELF__');
            }

            $this->_response->setHttpResponseCode(403);
        } elseif ($redirect !== null) {
            if (! $redirect instanceof Url) {
                $redirect = Url::fromPath($redirect);
            }

            if (($relativeUrl = $redirect->getRelativeUrl())) {
                $login->setParam('redirect', $relativeUrl);
            }
        }

        $this->rerenderLayout()->redirectNow($login);
    }

    protected function rerenderLayout()
    {
        $this->rerenderLayout = true;
        return $this;
    }

    public function isXhr()
    {
        return $this->getRequest()->isXmlHttpRequest();
    }

    protected function redirectXhr($url)
    {
        $response = $this->getResponse();

        if ($this->reloadCss) {
            $response->setReloadCss(true);
        }

        if ($this->rerenderLayout) {
            $response->setRerenderLayout(true);
        }

        $response->redirectAndExit($url);
    }

    protected function redirectHttp($url)
    {
        if ($this->isXhr()) {
            $this->getResponse()->setHeader('X-Icinga-Redirect-Http', 'yes');
        }

        $this->getResponse()->redirectAndExit($url);
    }

    /**
    *  Redirect to a specific url, updating the browsers URL field
    *
    *  @param Url|string $url The target to redirect to
    **/
    public function redirectNow($url)
    {
        if ($this->isXhr()) {
            $this->redirectXhr($url);
        } else {
            $this->redirectHttp($url);
        }
    }

    /**
     * @see Zend_Controller_Action::preDispatch()
     */
    public function preDispatch()
    {
        $form = new AutoRefreshForm();
        if (! $this->getRequest()->isApiRequest()) {
            $form->handleRequest();
        }
        $this->_helper->layout()->autoRefreshForm = $form;
    }

    /**
     * Detect whether the current request requires changes in the layout and apply them before rendering
     *
     * @see Zend_Controller_Action::postDispatch()
     */
    public function postDispatch()
    {
        Benchmark::measure('Action::postDispatch()');

        $req = $this->getRequest();
        $layout = $this->_helper->layout();
        $layout->innerLayout = $this->innerLayout;
        $layout->inlineLayout = $this->inlineLayout;

        if ($user = $req->getUser()) {
            if ((bool) $user->getPreferences()->getValue('icingaweb', 'show_benchmark', false)) {
                if ($this->_helper->layout()->isEnabled()) {
                    $layout->benchmark = $this->renderBenchmark();
                }
            }

            if (! (bool) $user->getPreferences()->getValue('icingaweb', 'auto_refresh', true)) {
                $this->disableAutoRefresh();
            }
        }

        if ($this->autorefreshInterval !== null) {
            $layout->autorefreshInterval = $this->autorefreshInterval;
        }

        if ($req->getParam('error_handler') === null && $req->getParam('format') === 'pdf') {
            $this->sendAsPdf();
            $this->shutdownSession();
            exit;
        }

        if ($this->isXhr()) {
            $this->postDispatchXhr();
        }

        $this->shutdownSession();
    }

    protected function postDispatchXhr()
    {
        $resp = $this->getResponse();

        if ($this->reloadCss) {
            $resp->setReloadCss(true);
        }

        if ($this->view->title) {
            if (preg_match('~[\r\n]~', $this->view->title)) {
                // TODO: Innocent exception and error log for hack attempts
                throw new IcingaException('No way, guy');
            }
            $resp->setHeader(
                'X-Icinga-Title',
                rawurlencode($this->view->title . ' :: ' . $this->view->defaultTitle),
                true
            );
        } else {
            $resp->setHeader('X-Icinga-Title', rawurlencode($this->view->defaultTitle), true);
        }

        $layout = $this->_helper->layout();
        if ($this->rerenderLayout) {
            $layout->setLayout($this->innerLayout);
            $resp->setRerenderLayout(true);
        } else {
            // The layout may be disabled and there's no indication that the layout is explicitly desired,
            // that's why we're passing false as second parameter to setLayout
            $layout->setLayout($this->inlineLayout, false);
        }

        if ($this->autorefreshInterval !== null) {
            $resp->setAutoRefreshInterval($this->autorefreshInterval);
        }
    }

    protected function sendAsPdf()
    {
        if (Module::exists('pdfexport')) {
            $this->newSendAsPdf();
        } else {
            $pdf = new Pdf();
            $pdf->renderControllerAction($this);
        }
    }

    protected function shutdownSession()
    {
        $session = Session::getSession();
        if ($session->hasChanged()) {
            $session->write();
        }
    }

    /**
     * Render the benchmark
     *
     * @return string Benchmark HTML
     */
    protected function renderBenchmark()
    {
        $this->_helper->viewRenderer->postDispatch();
        Benchmark::measure('Response ready');
        return Benchmark::renderToHtml();
    }

    /**
     * Try to call compatible methods from older zend versions
     *
     * Methods like getParam and redirect are _getParam/_redirect in older Zend versions (which reside for example
     * in Debian Wheezy). Using those methods without the "_" causes the application to fail on those platforms, but
     * using the version with "_" forces us to use deprecated code. So we try to catch this issue by looking for methods
     * with the same name, but with a "_" prefix prepended.
     *
     * @param   string  $name   The method name to check
     * @param   mixed   $params The method parameters
     * @return  mixed           Anything the method returns
     */
    public function __call($name, $params)
    {
        $deprecatedMethod = '_' . $name;

        if (method_exists($this, $deprecatedMethod)) {
            return call_user_func_array(array($this, $deprecatedMethod), $params);
        }

        return parent::__call($name, $params);
    }
}
