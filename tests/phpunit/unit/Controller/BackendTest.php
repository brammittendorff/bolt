<?php
namespace Bolt\Tests\Controller;

use Bolt\Configuration\ResourceManager;
use Bolt\Controllers\Backend;
use Bolt\Storage;
use Bolt\Tests\BoltUnitTest;
use Bolt\Tests\Mocks\LoripsumMock;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class to test correct operation of src/Controller/Backend.
 *
 * @author Ross Riley <riley.ross@gmail.com>
 **/

class BackendTest extends BoltUnitTest
{
    public function testDashboard()
    {
        $this->resetDb();
        $app = $this->getApp();
        $this->addSomeContent();
        $twig = $this->getMockTwig();
        $phpunit = $this;
        $testHandler = function ($template, $context) use ($phpunit) {
            $phpunit->assertEquals('dashboard/dashboard.twig', $template);
            $phpunit->assertNotEmpty($context['context']);
            $phpunit->assertArrayHasKey('latest', $context['context']);
            $phpunit->assertArrayHasKey('suggestloripsum', $context['context']);

            return new Response();
        };

        $twig->expects($this->any())
            ->method('render')
            ->will($this->returnCallBack($testHandler));
        $this->allowLogin($app);
        $app['render'] = $twig;
        $request = Request::create('/bolt');
        $app->run($request);
    }

    public function testClearCache()
    {
        $app = $this->getApp();
        $this->allowLogin($app);
        $cache = $this->getMock('Bolt\Cache', array('clearCache'), array(__DIR__, $app));
        $cache->expects($this->at(0))
            ->method('clearCache')
            ->will($this->returnValue(array('successfiles' => '1.txt', 'failedfiles' => '2.txt')));

        $cache->expects($this->at(1))
            ->method('clearCache')
            ->will($this->returnValue(array('successfiles' => '1.txt')));

        $app['cache'] = $cache;
        $request = Request::create('/bolt/clearcache');
        $this->checkTwigForTemplate($app, 'clearcache/clearcache.twig');
        $response = $app->handle($request);

        $this->assertNotEmpty($app['session']->getFlashBag()->get('error'));

        $request = Request::create('/bolt/clearcache');
        $this->checkTwigForTemplate($app, 'clearcache/clearcache.twig');
        $response = $app->handle($request);
        $this->assertNotEmpty($app['session']->getFlashBag()->get('success'));
    }

    public function testOmnisearch()
    {
        $app = $this->getApp();
        $this->allowLogin($app);

        $request = Request::create('/bolt/omnisearch', 'GET', array('q' => 'test'));
        $this->checkTwigForTemplate($app, 'omnisearch/omnisearch.twig');

        $app->run($request);
    }

    public function testPrefill()
    {
        $app = $this->getApp();
        $controller = new Backend();

        $app['request'] =  $request = Request::create('/bolt/prefill');
        $response = $controller->prefill($app, $request);
        $context = $response->getContext();
        $this->assertEquals(3, count($context['context']['contenttypes']));
        $this->assertInstanceOf('Symfony\Component\Form\FormView', $context['context']['form']);

        // Test the post
        $app['request'] = $request = Request::create('/bolt/prefill', 'POST', array('contenttypes' => 'pages'));
        $response = $controller->prefill($app, $request);
        $this->assertEquals('/bolt/prefill', $response->getTargetUrl());

        // Test for the Exception if connection fails to the prefill service
        $store = $this->getMock('Bolt\Storage', array('preFill'), array($app));

        $this->markTestIncomplete(
            'Needs work.'
        );

        if ($app['deprecated.php']) {
            $store->expects($this->any())
                ->method('preFill')
                ->will($this->returnCallback(function () {
                    throw new \Guzzle\Http\Exception\RequestException();
            }));
        } else {
            $request = new \GuzzleHttp\Message\Request('GET', '');
            $store->expects($this->any())
                ->method('preFill')
                ->will($this->returnCallback(function () use ($request) {
                    throw new \GuzzleHttp\Exception\RequestException('', $request);
            }));
        }

        $app['storage'] = $store;

        $logger = $this->getMock('Monolog\Logger', array('error'), array('test'));
        $logger->expects($this->once())
            ->method('error')
            ->with("Timeout attempting to the 'Lorem Ipsum' generator. Unable to add dummy content.");
        $app['logger.system'] = $logger;

        $app['request'] = $request = Request::create('/bolt/prefill', 'POST', array('contenttypes' => 'pages'));
        $response = $controller->prefill($app, $request);
    }

    public function testOverview()
    {
        $app = $this->getApp();
        $controller = new Backend();

        $app['request'] = $request = Request::create('/bolt/overview/pages');
        $response = $controller->overview($app, 'pages');
        $context = $response->getContext();
        $this->assertEquals('Pages', $context['context']['contenttype']['name']);
        $this->assertGreaterThan(1, count($context['context']['multiplecontent']));

        // Test the the default records per page can be set
        $app['request'] = $request = Request::create('/bolt/overview/showcases');
        $response = $controller->overview($app, 'showcases');

        // Test redirect when user isn't allowed.
        $users = $this->getMock('Bolt\Users', array('isAllowed'), array($app));
        $users->expects($this->once())
            ->method('isAllowed')
            ->will($this->returnValue(false));
        $app['users'] = $users;

        $app['request'] = $request = Request::create('/bolt/overview/pages');
        $response = $controller->overview($app, 'pages');
        $this->assertEquals('/bolt', $response->getTargetUrl());
    }

    public function testOverviewFiltering()
    {
        $app = $this->getApp();
        $controller = new Backend();

        $app['request'] = $request = Request::create(
            '/bolt/overview/pages',
            'GET',
            array(
                'filter'            => 'Lorem',
                'taxonomy-chapters' => 'main'
            )
        );
        $response = $controller->overview($app, 'pages');
        $context = $response->getContext();
        $this->assertArrayHasKey('filter', $context['context']);
        $this->assertEquals('Lorem', $context['context']['filter'][0]);
        $this->assertEquals('main', $context['context']['filter'][1]);
    }

    public function testRelatedTo()
    {
        $app = $this->getApp();
        $controller = new Backend();

        $app['request'] = $request = Request::create('/bolt/relatedto/showcases/1');
        $response = $controller->relatedTo('showcases', 1, $app, $request);
        $context = $response->getContext();
        $this->assertEquals(1, $context['context']['id']);
        $this->assertEquals('Showcase', $context['context']['name']);
        $this->assertEquals('Showcases', $context['context']['contenttype']['name']);
        $this->assertEquals(2, count($context['context']['relations']));
        // By default we show the first one
        $this->assertEquals('Entries', $context['context']['show_contenttype']['name']);

        // Now we specify we want to see pages
        $app['request'] = $request = Request::create('/bolt/relatedto/showcases/1', 'GET', array('show' => 'pages'));
        $response = $controller->relatedTo('showcases', 1, $app, $request);
        $context = $response->getContext();
        $this->assertEquals('Pages', $context['context']['show_contenttype']['name']);

        // Try a request where there are no relations
        $app['request'] = $request = Request::create('/bolt/relatedto/pages/1');
        $response = $controller->relatedTo('pages', 1, $app, $request);
        $context = $response->getContext();
        $this->assertNull($context['context']['relations']);

        // Test redirect when user isn't allowed.
        $users = $this->getMock('Bolt\Users', array('isAllowed'), array($app));
        $users->expects($this->once())
            ->method('isAllowed')
            ->will($this->returnValue(false));
        $app['users'] = $users;

        $app['request'] = $request = Request::create('/bolt/relatedto/showcases/1');
        $response = $controller->relatedTo('showcases', 1, $app, $request);
        $this->assertEquals('/bolt', $response->getTargetUrl());
    }

    public function testEditContentGet()
    {
        $app = $this->getApp();
        $controller = new Backend();

        // First test will fail permission so we check we are kicked back to the dashboard
        $app['request'] = $request = Request::create('/bolt/editcontent/pages/4');
        $response = $controller->editContent('pages', 4, $app, $request);
        $this->assertEquals("/bolt", $response->getTargetUrl());

        // Since we're the test user we won't automatically have permission to edit.
        $users = $this->getMock('Bolt\Users', array('isAllowed'), array($app));
        $users->expects($this->any())
            ->method('isAllowed')
            ->will($this->returnValue(true));
        $app['users'] = $users;

        $app['request'] = $request = Request::create('/bolt/editcontent/pages/4');
        $response = $controller->editContent('pages', 4, $app, $request);
        $context = $response->getContext();
        $this->assertEquals('Pages', $context['context']['contenttype']['name']);
        $this->assertInstanceOf('Bolt\Content', $context['context']['content']);

        // Test creation
        $app['request'] = $request = Request::create('/bolt/editcontent/pages');
        $response = $controller->editContent('pages', null, $app, $request);
        $context = $response->getContext();
        $this->assertEquals('Pages', $context['context']['contenttype']['name']);
        $this->assertInstanceOf('Bolt\Content', $context['context']['content']);
        $this->assertNull($context['context']['content']->id);

        // Test that non-existent throws a redirect
        $app['request'] = $request = Request::create('/bolt/editcontent/pages/310');
        $this->setExpectedException('Symfony\Component\HttpKernel\Exception\HttpException', 'not-existing');
        $response = $controller->editContent('pages', 310, $app, $request);
    }

    public function testEditContentDuplicate()
    {
        $app = $this->getApp();
        $controller = new Backend();
        // Since we're the test user we won't automatically have permission to edit.
        $users = $this->getMock('Bolt\Users', array('isAllowed'), array($app));
        $users->expects($this->any())
            ->method('isAllowed')
            ->will($this->returnValue(true));
        $app['users'] = $users;

        $app['request'] = $request = Request::create('/bolt/editcontent/pages/4', 'GET', array('duplicate' => true));
        $original = $app['storage']->getContent('pages/4');
        $response = $controller->editContent('pages', 4, $app, $request);
        $context = $response->getContext();

        // Check that correct fields are equal in new object
        $new = $context['context']['content'];
        $this->assertEquals($new['body'], $original['body']);
        $this->assertEquals($new['title'], $original['title']);
        $this->assertEquals($new['teaser'], $original['teaser']);

        // Check that some have been cleared.
        $this->assertEquals('', $new['id']);
        $this->assertEquals('', $new['slug']);
        $this->assertEquals('', $new['ownerid']);
    }

    public function testEditContentCSRF()
    {
        $app = $this->getApp();
        $controller = new Backend();
        $users = $this->getMock('Bolt\Users', array('isAllowed', 'checkAntiCSRFToken'), array($app));
        $users->expects($this->any())
            ->method('isAllowed')
            ->will($this->returnValue(true));
        $users->expects($this->any())
            ->method('checkAntiCSRFToken')
            ->will($this->returnValue(false));

        $app['users'] = $users;

        $app['request'] = $request = Request::create('/bolt/editcontent/showcases/3', 'POST');
        $this->setExpectedException('Symfony\Component\HttpKernel\Exception\HttpException', 'Something went wrong');
        $response = $controller->editContent('showcases', 3, $app, $request);
    }

    public function testEditContentPermissions()
    {
        $app = $this->getApp();

        $users = $this->getMock('Bolt\Users', array('isAllowed', 'checkAntiCSRFToken'), array($app));
        $users->expects($this->at(0))
            ->method('isAllowed')
            ->will($this->returnValue(true));

        $users->expects($this->any())
            ->method('checkAntiCSRFToken')
            ->will($this->returnValue(true));

        $app['users'] = $users;

        // We should get kicked here because we dont have permissions to edit this
        $controller = new Backend();
        $app['request'] = $request = Request::create('/bolt/editcontent/showcases/3', 'POST');
        $response = $controller->editContent('showcases', 3, $app, $request);
        $this->assertEquals("/bolt", $response->getTargetUrl());
    }

    public function testEditContentPost()
    {
        $app = $this->getApp();
        $controller = new Backend();

        $users = $this->getMock('Bolt\Users', array('isAllowed', 'checkAntiCSRFToken'), array($app));
        $users->expects($this->any())
            ->method('isAllowed')
            ->will($this->returnValue(true));
        $users->expects($this->any())
            ->method('checkAntiCSRFToken')
            ->will($this->returnValue(true));

        $app['users'] = $users;

        $app['request'] = $request = Request::create('/bolt/editcontent/showcases/3', 'POST', array('floatfield' => 1.2));
        $original = $app['storage']->getContent('showcases/3');
        $response = $controller->editContent('showcases', 3, $app, $request);
        $this->assertEquals('/bolt/overview/showcases', $response->getTargetUrl());
    }

    public function testEditContentPostAjax()
    {
        $app = $this->getApp();
        $controller = new Backend();

        // Since we're the test user we won't automatically have permission to edit.
        $users = $this->getMock('Bolt\Users', array('isAllowed', 'checkAntiCSRFToken'), array($app));
        $users->expects($this->any())
            ->method('isAllowed')
            ->will($this->returnValue(true));
        $users->expects($this->any())
            ->method('checkAntiCSRFToken')
            ->will($this->returnValue(true));

        $app['users'] = $users;

        $app['request'] = $request = Request::create('/bolt/editcontent/pages/4?returnto=ajax', 'POST');
        $original = $app['storage']->getContent('pages/4');
        $response = $controller->editContent('pages', 4, $app, $request);
        $this->assertInstanceOf('Symfony\Component\HttpFoundation\JsonResponse', $response);
        $returned = json_decode($response->getContent());
        $this->assertEquals($original['title'], $returned->title);
    }

    public function testDeleteContent()
    {
        $app = $this->getApp();
        $controller = new Backend();

        $app['request'] = $request = Request::create('/bolt/deletecontent/pages/4');
        $response = $controller->deleteContent($app, 'pages', 4);
        // This one should fail for permissions
        $this->assertEquals('/bolt/overview/pages', $response->getTargetUrl());
        $err = $app['session']->getFlashBag()->get('error');
        $this->assertRegexp('/denied/', $err[0]);

        $users = $this->getMock('Bolt\Users', array('isAllowed', 'checkAntiCSRFToken'), array($app));
        $users->expects($this->any())
            ->method('isAllowed')
            ->will($this->returnValue(true));
        $app['users'] = $users;

        // This one should get killed by the anti CSRF check
        $response = $controller->deleteContent($app, 'pages', 4);
        $this->assertEquals('/bolt/overview/pages', $response->getTargetUrl());
        $err = $app['session']->getFlashBag()->get('info');
        $this->assertRegexp('/could not be deleted/', $err[0]);

        $app['users']->expects($this->any())
            ->method('checkAntiCSRFToken')
            ->will($this->returnValue(true));

        $response = $controller->deleteContent($app, 'pages', 4);
        $this->assertEquals('/bolt/overview/pages', $response->getTargetUrl());
        $err = $app['session']->getFlashBag()->get('info');
        $this->assertRegexp('/has been deleted/', $err[0]);
    }

    public function testContentAction()
    {
        // Try status switches
        $app = $this->getApp();
        $controller = new Backend();

        $app['request'] = $request = Request::create('/bolt/content/held/pages/3');

        // This one should fail for lack of permission
        $response = $controller->contentAction($app, 'held', 'pages', 3);
        $this->assertEquals('/bolt/overview/pages', $response->getTargetUrl());
        $err = $app['session']->getFlashBag()->get('error');
        $this->assertRegexp('/right privileges/', $err[0]);

        $users = $this->getMock('Bolt\Users', array('isAllowed', 'checkAntiCSRFToken', 'isContentStatusTransitionAllowed'), array($app));
        $users->expects($this->any())
            ->method('isAllowed')
            ->will($this->returnValue(true));
        $app['users'] = $users;

        // This one should fail for the second permission check `isContentStatusTransitionAllowed`
        $response = $controller->contentAction($app, 'held', 'pages', 3);
        $this->assertEquals('/bolt/overview/pages', $response->getTargetUrl());
        $err = $app['session']->getFlashBag()->get('error');
        $this->assertRegexp('/right privileges/', $err[0]);

        $app['users']->expects($this->any())
            ->method('isContentStatusTransitionAllowed')
            ->will($this->returnValue(true));

        $response = $controller->contentAction($app, 'held', 'pages', 3);
        $this->assertEquals('/bolt/overview/pages', $response->getTargetUrl());
        $err = $app['session']->getFlashBag()->get('info');
        $this->assertRegexp('/has been changed/', $err[0]);

        // Test an invalid action fails
        $app['request'] = $request = Request::create('/bolt/content/fake/pages/3');
        $response = $controller->contentAction($app, 'fake', 'pages', 3);
        $err = $app['session']->getFlashBag()->get('error');
        $this->assertRegexp('/No such action/', $err[0]);

        // Test that any save error gets reported
        $app['request'] = $request = Request::create('/bolt/content/held/pages/3');

        $storage = $this->getMock('Bolt\Storage', array('updateSingleValue'), array($app));
        $storage->expects($this->once())
            ->method('updateSingleValue')
            ->will($this->returnValue(false));

        $app['storage'] = $storage;

        $response = $controller->contentAction($app, 'held', 'pages', 3);
        $this->assertEquals('/bolt/overview/pages', $response->getTargetUrl());
        $err = $app['session']->getFlashBag()->get('info');
        $this->assertRegexp('/could not be modified/', $err[0]);

        // Test the delete proxy action
        // Note that the response will be 'could not be deleted'. Since this just
        // passes on the the deleteContent method that is enough to indicate that
        // the work of this method is done.
        $app['request'] = $request = Request::create('/bolt/content/delete/pages/3');
        $response = $controller->contentAction($app, 'delete', 'pages', 3);
        $this->assertEquals('/bolt/overview/pages', $response->getTargetUrl());
        $err = $app['session']->getFlashBag()->get('info');
        $this->assertRegexp('/could not be deleted/', $err[0]);
    }

    public function testAbout()
    {
        $app = $this->getApp();
        $controller = new Backend();
        $app['request'] = $request = Request::create('/bolt/about');
        $response = $controller->about($app);
        $this->assertEquals('about/about.twig', $response->getTemplateName());
    }

    public function testTranslation()
    {
        // We make a new translation and ensure that the content is created.
        $app = $this->getApp();
        $controller = new Backend();
        $this->removeCSRF($app);
        $app['request'] = $request = Request::create('/bolt/tr/contenttypes/en_CY');
        $response = $controller->translation('contenttypes', 'en_CY', $app, $request);
        $context = $response->getContext();
        $this->assertEquals('contenttypes.en_CY.yml', $context['context']['basename']);

        // Now try and post the update
        $app['request'] = $request = Request::create(
            '/bolt/tr/contenttypes/en_CY',
            'POST',
            array(
                'form' => array(
                    'contents' => 'test content at least 10 chars',
                    '_token'   => 'xyz'
                )
            )
        );
        $response = $controller->translation('contenttypes', 'en_CY', $app, $request);
        $context = $response->getContext();
        $this->assertEquals('editlocale/editlocale.twig', $response->getTemplateName());

        // Write isn't allowed initially so check the error
        $error = $app['session']->getFlashBag()->get('error');
        $this->assertRegexp('/is not writable/', $error[0]);

        // Check that YML parse errors get caught
        $app['request'] = $request = Request::create(
            '/bolt/tr/contenttypes/en_CY',
            'POST',
            array(
                'form' => array(
                    'contents' => "- this is invalid yaml markup: *thisref",
                    '_token'   => 'xyz'
                )
            )
        );
        $response = $controller->translation('contenttypes', 'en_CY', $app, $request);
        $info = $app['session']->getFlashBag()->get('error');
        $this->assertRegexp('/could not be saved/', $info[0]);
    }

    protected function addSomeContent()
    {
        $app = $this->getApp();
        $this->addDefaultUser($app);
        $app['config']->set('taxonomy/categories/options', array('news'));
        $prefillMock = new LoripsumMock();
        $app['prefill'] = $prefillMock;

        $storage = new Storage($app);
        $storage->prefill(array('showcases', 'pages'));
    }
}
