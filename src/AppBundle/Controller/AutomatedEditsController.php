<?php
/**
 * This file contains only the AutomatedEditsController class.
 */

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Xtools\AutoEdits;
use Xtools\AutoEditsRepository;

/**
 * This controller serves the AutomatedEdits tool.
 */
class AutomatedEditsController extends XtoolsController
{
    /** @var AutoEdits The AutoEdits instance. */
    protected $autoEdits;

    /** @var array Data that is passed to the view. */
    private $output;

    /**
     * Get the name of the tool's index route.
     * This is also the name of the associated model.
     * @return string
     * @codeCoverageIgnore
     */
    public function getIndexRoute()
    {
        return 'AutoEdits';
    }

    /**
     * AutomatedEditsController constructor.
     * @param RequestStack $requestStack
     * @param ContainerInterface $container
     */
    public function __construct(RequestStack $requestStack, ContainerInterface $container)
    {
        // This will cause the tool to redirect back to the index page, with an error,
        // if the user has too high of an edit count.
        $this->tooHighEditCountAction = $this->getIndexRoute();

        parent::__construct($requestStack, $container);
    }

    /**
     * Display the search form.
     * @Route("/autoedits", name="AutoEdits")
     * @Route("/autoedits/", name="AutoEditsSlash")
     * @Route("/automatededits", name="AutoEditsLong")
     * @Route("/automatededits/", name="AutoEditsLongSlash")
     * @Route("/autoedits/index.php", name="AutoEditsIndexPhp")
     * @Route("/automatededits/index.php", name="AutoEditsLongIndexPhp")
     * @Route("/autoedits/{project}", name="AutoEditsProject")
     * @return Response
     */
    public function indexAction()
    {
        // Redirect if at minimum project and username are provided.
        if (isset($this->params['project']) && isset($this->params['username'])) {
            return $this->redirectToRoute('AutoEditsResult', $this->params);
        }

        return $this->render('autoEdits/index.html.twig', array_merge([
            'xtPageTitle' => 'tool-autoedits',
            'xtSubtitle' => 'tool-autoedits-desc',
            'xtPage' => 'autoedits',

            // Defaults that will get overridden if in $this->params.
            'namespace' => 0,
            'start' => '',
            'end' => '',
        ], $this->params));
    }

    /**
     * Set defaults, and instantiate the AutoEdits model. This is called at the top of every view action.
     * @codeCoverageIgnore
     */
    private function setupAutoEdits()
    {
        // Format dates as needed by User model, if the date is present.
        if ($this->start !== false) {
            $this->start = date('Y-m-d', $this->start);
        }
        if ($this->end !== false) {
            $this->end = date('Y-m-d', $this->end);
        }

        // Check query param for the tool name.
        $tool = $this->request->query->get('tool', null);

        $this->autoEdits = new AutoEdits(
            $this->project,
            $this->user,
            $this->namespace,
            $this->start,
            $this->end,
            $tool,
            $this->offset
        );
        $autoEditsRepo = new AutoEditsRepository();
        $autoEditsRepo->setContainer($this->container);
        $this->autoEdits->setRepository($autoEditsRepo);

        $this->output = [
            'xtPage' => 'autoedits',
            'xtTitle' => $this->user->getUsername(),
            'project' => $this->project,
            'user' => $this->user,
            'ae' => $this->autoEdits,
            'is_sub_request' => $this->isSubRequest,
        ];
    }

    /**
     * Display the results.
     * @Route(
     *     "/autoedits/{project}/{username}/{namespace}/{start}/{end}", name="AutoEditsResult",
     *     requirements={
     *         "namespace" = "|all|\d+",
     *         "start" = "|\d{4}-\d{2}-\d{2}",
     *         "end" = "|\d{4}-\d{2}-\d{2}",
     *         "namespace" = "|all|\d+"
     *     },
     *     defaults={"namespace" = 0, "start" = "", "end" = ""}
     * )
     * @return RedirectResponse|Response
     * @codeCoverageIgnore
     */
    public function resultAction()
    {
        // Will redirect back to index if the user has too high of an edit count.
        $this->setupAutoEdits();

        // Render the view with all variables set.
        return $this->render('autoEdits/result.html.twig', $this->output);
    }

    /**
     * Get non-automated edits for the given user.
     * @Route(
     *   "/nonautoedits-contributions/{project}/{username}/{namespace}/{start}/{end}/{offset}",
     *   name="NonAutoEditsContributionsResult",
     *   requirements={
     *       "namespace" = "|all|\d+",
     *       "start" = "|\d{4}-\d{2}-\d{2}",
     *       "end" = "|\d{4}-\d{2}-\d{2}",
     *       "offset" = "\d*"
     *   },
     *   defaults={"namespace" = 0, "start" = "", "end" = "", "offset" = 0}
     * )
     * @return Response|RedirectResponse
     * @codeCoverageIgnore
     */
    public function nonAutomatedEditsAction()
    {
        $this->setupAutoEdits();

        return $this->render('autoEdits/nonautomated_edits.html.twig', $this->output);
    }

    /**
     * Get automated edits for the given user using the given tool.
     * @Route(
     *   "/autoedits-contributions/{project}/{username}/{namespace}/{start}/{end}/{offset}",
     *   name="AutoEditsContributionsResult",
     *   requirements={
     *       "namespace" = "|all|\d+",
     *       "start" = "|\d{4}-\d{2}-\d{2}",
     *       "end" = "|\d{4}-\d{2}-\d{2}",
     *       "offset" = "\d*"
     *   },
     *   defaults={"namespace" = 0, "start" = "", "end" = "", "offset" = 0}
     * )
     * @return Response|RedirectResponse
     * @codeCoverageIgnore
     */
    public function automatedEditsAction()
    {
        $this->setupAutoEdits();

        return $this->render('autoEdits/automated_edits.html.twig', $this->output);
    }

    /************************ API endpoints ************************/

    /**
     * Get a list of the automated tools and their regex/tags/etc.
     * @Route("/api/user/automated_tools/{project}", name="UserApiAutoEditsTools")
     * @return JsonResponse
     * @codeCoverageIgnore
     */
    public function automatedToolsApiAction()
    {
        $this->recordApiUsage('user/automated_tools');

        $aeh = $this->container->get('app.automated_edits_helper');
        return new JsonResponse($aeh->getTools($this->project));
    }

    /**
     * Count the number of automated edits the given user has made.
     * @Route(
     *   "/api/user/automated_editcount/{project}/{username}/{namespace}/{start}/{end}/{tools}",
     *   name="UserApiAutoEditsCount",
     *   requirements={
     *       "namespace" = "|all|\d+",
     *       "start" = "|\d{4}-\d{2}-\d{2}",
     *       "end" = "|\d{4}-\d{2}-\d{2}"
     *   },
     *   defaults={"namespace" = "all", "start" = "", "end" = ""}
     * )
     * @param string $tools Non-blank to show which tools were used and how many times.
     * @return JsonResponse
     * @codeCoverageIgnore
     */
    public function automatedEditCountApiAction($tools = '')
    {
        $this->recordApiUsage('user/automated_editcount');

        $this->setupAutoEdits();

        $res = $this->getJsonData();
        $res['total_editcount'] = $this->autoEdits->getEditCount();

        $response = new JsonResponse();
        $response->setEncodingOptions(JSON_NUMERIC_CHECK);

        $res['automated_editcount'] = $this->autoEdits->getAutomatedCount();
        $res['nonautomated_editcount'] = $res['total_editcount'] - $res['automated_editcount'];

        if ($tools != '') {
            $tools = $this->autoEdits->getToolCounts();
            $res['automated_tools'] = $tools;
        }

        $response->setData($res);
        return $response;
    }

    /**
     * Get non-automated edits for the given user.
     * @Route(
     *   "/api/user/nonautomated_edits/{project}/{username}/{namespace}/{start}/{end}/{offset}",
     *   name="UserApiNonAutoEdits",
     *   requirements={
     *       "namespace" = "|all|\d+",
     *       "start" = "|\d{4}-\d{2}-\d{2}",
     *       "end" = "|\d{4}-\d{2}-\d{2}",
     *       "offset" = "\d*"
     *   },
     *   defaults={"namespace" = 0, "start" = "", "end" = "", "offset" = 0}
     * )
     * @return JsonResponse
     * @codeCoverageIgnore
     */
    public function nonAutomatedEditsApiAction()
    {
        $this->recordApiUsage('user/nonautomated_edits');

        $this->setupAutoEdits();

        $ret = $this->getJsonData();
        $ret['nonautomated_edits'] = $this->autoEdits->getNonAutomatedEdits(true);

        $namespaces = $this->project->getNamespaces();

        $ret['nonautomated_edits'] = array_map(function ($rev) use ($namespaces) {
            $pageTitle = $rev['page_title'];
            if ((int)$rev['page_namespace'] === 0) {
                $fullPageTitle = $pageTitle;
            } else {
                $fullPageTitle = $namespaces[$rev['page_namespace']].":$pageTitle";
            }

            return array_merge(['full_page_title' => $fullPageTitle], $rev);
        }, $ret['nonautomated_edits']);

        $response = new JsonResponse();
        $response->setEncodingOptions(JSON_NUMERIC_CHECK);

        $response->setData($ret);
        return $response;
    }

    /**
     * Get (semi-)automated edits for the given user, optionally using the given tool.
     * @Route(
     *   "/api/user/automated_edits/{project}/{username}/{namespace}/{start}/{end}/{offset}",
     *   name="UserNonAutoEdits",
     *   requirements={
     *       "namespace" = "|all|\d+",
     *       "start" = "|\d{4}-\d{2}-\d{2}",
     *       "end" = "|\d{4}-\d{2}-\d{2}",
     *       "offset" = "\d*"
     *   },
     *   defaults={"namespace" = 0, "start" = "", "end" = "", "offset" = 0}
     * )
     * @return Response
     * @codeCoverageIgnore
     */
    public function automatedEditsApiAction()
    {
        $this->recordApiUsage('user/automated_edits');

        $this->setupAutoEdits();

        $ret = $this->getJsonData();
        $ret['nonautomated_edits'] = $this->autoEdits->getAutomatedEdits(true);

        $namespaces = $this->project->getNamespaces();

        $ret['nonautomated_edits'] = array_map(function ($rev) use ($namespaces) {
            $pageTitle = $rev['page_title'];
            if ((int)$rev['page_namespace'] === 0) {
                $fullPageTitle = $pageTitle;
            } else {
                $fullPageTitle = $namespaces[$rev['page_namespace']].":$pageTitle";
            }

            return array_merge(['full_page_title' => $fullPageTitle], $rev);
        }, $ret['nonautomated_edits']);

        $response = new JsonResponse();
        $response->setEncodingOptions(JSON_NUMERIC_CHECK);

        $response->setData($ret);
        return $response;
    }

    /**
     * Get data that will be used in API responses.
     * @return array
     * @codeCoverageIgnore
     */
    private function getJsonData()
    {
        $ret = [
            'project' => $this->project->getDomain(),
            'username' => $this->user->getUsername(),
        ];

        foreach (['namespace', 'start', 'end', 'offset'] as $param) {
            if (isset($this->{$param}) && $this->{$param} != '') {
                $ret[$param] = $this->{$param};
            }
        }

        return $ret;
    }
}
