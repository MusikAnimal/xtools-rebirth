<?php
/**
 * This file contains only the TopEditsController class.
 */

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Xtools\Project;
use Xtools\User;
use Xtools\TopEdits;
use Xtools\TopEditsRepository;

/**
 * The Top Edits tool.
 */
class TopEditsController extends XtoolsController
{

    /**
     * Get the name of the tool's index route.
     * This is also the name of the associated model.
     * @return string
     * @codeCoverageIgnore
     */
    public function getIndexRoute()
    {
        return 'TopEdits';
    }

    /**
     * TopEditsController constructor.
     * @param RequestStack $requestStack
     * @param ContainerInterface $container
     */
    public function __construct(RequestStack $requestStack, ContainerInterface $container)
    {
        $this->tooHighEditCountAction = $this->getIndexRoute();

        parent::__construct($requestStack, $container);
    }

    /**
     * Display the form.
     * @Route("/topedits", name="topedits")
     * @Route("/topedits", name="TopEdits")
     * @Route("/topedits/", name="topEditsSlash")
     * @Route("/topedits/index.php", name="TopEditsIndex")
     * @Route("/topedits/{project}", name="TopEditsProject")
     * @param Request $request
     * @return Response
     */
    public function indexAction(Request $request)
    {
        // Redirect if at minimum project and username are provided.
        if (isset($this->params['project']) && isset($this->params['username'])) {
            return $this->redirectToRoute('TopEditsResult', $this->params);
        }

        return $this->render('topedits/index.html.twig', array_merge([
            'xtPageTitle' => 'tool-topedits',
            'xtSubtitle' => 'tool-topedits-desc',
            'xtPage' => 'topedits',

            // Defaults that will get overriden if in $params.
            'namespace' => 0,
            'page' => '',
        ], $this->params));
    }

    /**
     * Display the results.
     * @Route("/topedits/{project}/{username}/{namespace}/{page}", name="TopEditsResult",
     *     requirements = {"page"=".+", "namespace" = "|all|\d+"}
     * )
     * @param int $namespace
     * @param string $page
     * @return RedirectResponse|Response
     * @codeCoverageIgnore
     */
    public function resultAction($namespace = 0, $page = '')
    {
        if ($page === '') {
            return $this->namespaceTopEdits($namespace);
        } else {
            return $this->singlePageTopEdits($namespace, $page);
        }
    }

    /**
     * List top edits by this user for all pages in a particular namespace.
     * @param integer|string $namespace The namespace ID or 'all'
     * @return Response
     * @codeCoverageIgnore
     */
    public function namespaceTopEdits($namespace)
    {
        $isSubRequest = $this->request->get('htmlonly')
            || $this->container->get('request_stack')->getParentRequest() !== null;

        // Make sure they've opted in to see this data.
        if (!$this->project->userHasOptedIn($this->user)) {
            $optedInPage = $this->project
                ->getRepository()
                ->getPage($this->project, $project->userOptInPage($this->user));

            return $this->render('topedits/result_namespace.html.twig', [
                'xtPage' => 'topedits',
                'xtTitle' => $this->user->getUsername(),
                'project' => $this->project,
                'user' => $this->user,
                'namespace' => $namespace,
                'opted_in_page' => $optedInPage,
                'is_sub_request' => $isSubRequest,
            ]);
        }

        /**
         * Max number of rows per namespace to show. `null` here will cause to
         * use the TopEdits default.
         * @var int
         */
        $limit = $isSubRequest ? 10 : null;

        $topEdits = new TopEdits($project, $user, null, $namespace, $limit);
        $topEditsRepo = new TopEditsRepository();
        $topEditsRepo->setContainer($this->container);
        $topEdits->setRepository($topEditsRepo);

        $topEdits->prepareData();

        $ret = [
            'xtPage' => 'topedits',
            'xtTitle' => $user->getUsername(),
            'project' => $project,
            'user' => $user,
            'namespace' => $namespace,
            'te' => $topEdits,
            'is_sub_request' => $isSubRequest,
        ];

        // Output the relevant format template.
        return $this->getFormattedResponse($request, 'topedits/result_namespace', $ret);
    }

    /**
     * List top edits by this user for a particular page.
     * @param int $namespaceId The ID of the namespace of the page.
     * @param string $pageName The title (without namespace) of the page.
     * @return RedirectResponse|\Symfony\Component\HttpFoundation\Response
     * @codeCoverageIgnore
     */
    protected function singlePageTopEdits($namespaceId, $pageName)
    {
        // Get the full page name (i.e. no namespace prefix if NS 0).
        $namespaces = $this->project->getNamespaces();

        // ********************************************************************
        // ********************************************************************
        // ********************************************************************
        // ********************************************************************
        // FIXME: XtoolsController assumes 'page' param includes namespace.
        $fullPageName = $namespaceId ? $namespaces[$namespaceId].':'.$pageName : $pageName;

        $page = $this->getAndValidatePage($this->project, $fullPageName);
        if (is_a($page, 'Symfony\Component\HttpFoundation\RedirectResponse')) {
            return $page;
        }

        // FIXME: add pagination.
        $topEdits = new TopEdits($this->project, $this->user, $page);
        $topEditsRepo = new TopEditsRepository();
        $topEditsRepo->setContainer($this->container);
        $topEdits->setRepository($topEditsRepo);

        $topEdits->prepareData();

        // Send all to the template.
        return $this->render('topedits/result_article.html.twig', [
            'xtPage' => 'topedits',
            'xtTitle' => $user->getUsername() . ' - ' . $page->getTitle(),
            'project' => $this->project,
            'user' => $this->user,
            'page' => $page,
            'te' => $topEdits,
        ]);
    }

    /************************ API endpoints ************************/

    /**
     * Get the all edits of a user to a specific page, maximum 1000.
     * @Route("/api/user/topedits/{project}/{username}/{namespace}/{page}", name="UserApiTopEditsArticle",
     *     requirements={"page"=".+", "namespace"="|\d+|all"})
     * @Route("/api/user/top_edits/{project}/{username}/{namespace}/{page}", name="UserApiTopEditsArticleUnderscored",
     *     requirements={"page"=".+", "namespace"="|\d+|all"})
     * @param Request $request
     * @param int|string $namespace The ID of the namespace of the page, or 'all' for all namespaces.
     * @param string $page The title of the page. A full title can be used if the $namespace is blank.
     * @return Response
     * TopEdits and its Repo cannot be stubbed here :(
     * @codeCoverageIgnore
     */
    public function topEditsUserApiAction(Request $request, $namespace = 0, $page = '')
    {
        $this->recordApiUsage('user/topedits');

        // Second parameter causes it return a Redirect to the index if the user has too many edits.
        // We only want to do this when looking at the user's overall edits, not just to a specific page.
        $ret = $this->validateProjectAndUser($request, $page !== '' ?  null : 'TopEdits');
        if ($ret instanceof RedirectResponse) {
            return $ret;
        } else {
            list($project, $user) = $ret;
        }

        if (!$project->userHasOptedIn($user)) {
            return new JsonResponse(
                [
                    'error' => 'User:'.$user->getUsername().' has not opted in to detailed statistics.'
                ],
                Response::HTTP_FORBIDDEN
            );
        }

        $limit = $page === '' ? 100 : 1000;
        $topEdits = new TopEdits($project, $user, null, $namespace, $limit);
        $topEditsRepo = new TopEditsRepository();
        $topEditsRepo->setContainer($this->container);
        $topEdits->setRepository($topEditsRepo);

        $response = new JsonResponse();
        $response->setEncodingOptions(JSON_NUMERIC_CHECK);

        if ($page === '') {
            // Do format the results.
            $topEdits->prepareData();
        } else {
            $namespaces = $project->getNamespaces();
            $fullPageName = is_numeric($namespace) ? $namespaces[$namespace].':'.$page : $page;

            $page = $this->getAndValidatePage($project, $fullPageName);
            if (is_a($page, 'Symfony\Component\HttpFoundation\RedirectResponse')) {
                $response->setData([
                    'error' => 'Page "'.$page.'" does not exist.',
                ]);
                $response->setStatusCode(Response::HTTP_NOT_FOUND);
                return $response;
            }

            $topEdits->setPage($page);
            $topEdits->prepareData(false);
        }

        $response->setData($topEdits->getTopEdits());
        $response->setStatusCode(Response::HTTP_OK);

        return $response;
    }
}
