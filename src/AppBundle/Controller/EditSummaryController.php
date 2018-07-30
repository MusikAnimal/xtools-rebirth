<?php
/**
 * This file contains only the SimpleEditCounterController class.
 */

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Xtools\EditSummary;
use Xtools\EditSummaryRepository;

/**
 * This controller handles the Simple Edit Counter tool.
 */
class EditSummaryController extends XtoolsController
{
    /**
     * Get the name of the tool's index route.
     * This is also the name of the associated model.
     * @return string
     * @codeCoverageIgnore
     */
    public function getIndexRoute()
    {
        return 'EditSummary';
    }

    /**
     * The Edit Summary search form.
     * @Route("/editsummary", name="EditSummary")
     * @Route("/editsummary/", name="EditSummarySlash")
     * @Route("/editsummary/index.php", name="EditSummaryIndexPhp")
     * @Route("/editsummary/{project}", name="EditSummaryProject")
     * @return Response
     */
    public function indexAction()
    {
        // If we've got a project, user, and namespace, redirect to results.
        if (isset($this->params['project']) && isset($this->params['username'])) {
            return $this->redirectToRoute('EditSummaryResult', $this->params);
        }

        // Show the form.
        return $this->render('editSummary/index.html.twig', array_merge([
            'xtPageTitle' => 'tool-editsummary',
            'xtSubtitle' => 'tool-editsummary-desc',
            'xtPage' => 'editsummary',

            // Defaults that will get overriden if in $params.
            'namespace' => 0,
        ], $this->params));
    }

    /**
     * Display the Edit Summary results
     * @Route("/editsummary/{project}/{username}/{namespace}", name="EditSummaryResult")
     * @return Response
     * @codeCoverageIgnore
     */
    public function resultAction()
    {
        // Instantiate an EditSummary, treating the past 150 edits as 'recent'.
        $editSummary = new EditSummary($this->project, $this->user, $this->namespace, 150);
        $editSummaryRepo = new EditSummaryRepository();
        $editSummaryRepo->setContainer($this->container);
        $editSummary->setRepository($editSummaryRepo);
        $editSummary->setI18nHelper($this->container->get('app.i18n_helper'));

        $editSummary->prepareData();

        // Assign the values and display the template
        return $this->render(
            'editSummary/result.html.twig',
            [
                'xtPage' => 'editsummary',
                'xtTitle' => $this->user->getUsername(),
                'user' => $this->user,
                'project' => $this->project,
                'namespace' => $this->namespace,
                'es' => $editSummary,
            ]
        );
    }

    /************************ API endpoints ************************/

    /**
     * Get basic stats on the edit summary usage of a user.
     * @Route("/api/user/edit_summaries/{project}/{username}/{namespace}", name="UserApiEditSummaries")
     * @return JsonResponse
     * @codeCoverageIgnore
     */
    public function editSummariesApiAction()
    {
        $this->recordApiUsage('user/edit_summaries');

        // Instantiate an EditSummary, treating the past 150 edits as 'recent'.
        $editSummary = new EditSummary($this->project, $this->user, $this->namespace, 150);
        $editSummaryRepo = new EditSummaryRepository();
        $editSummaryRepo->setContainer($this->container);
        $editSummary->setRepository($editSummaryRepo);
        $editSummary->prepareData();

        return new JsonResponse(
            $editSummary->getData(),
            Response::HTTP_OK
        );
    }
}
