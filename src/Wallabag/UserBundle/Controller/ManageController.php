<?php

namespace Wallabag\UserBundle\Controller;

use FOS\UserBundle\Event\UserEvent;
use FOS\UserBundle\FOSUserEvents;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Exception\OutOfRangeCurrentPageException;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Wallabag\UserBundle\Entity\User;
use Wallabag\UserBundle\Form\SearchUserType;

/**
 * User controller.
 */
class ManageController extends Controller
{
    /**
     * Lists all User entities.
     *
     * @Route("/list/{page}", name="user_index")
     * @Method("GET")
     *
     * @param int $page
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function indexAction($page = 1)
    {
        $em = $this->getDoctrine()->getManager();

        $qb = $em->getRepository('WallabagUserBundle:User')->createQueryBuilder('u');
        $pagerAdapter = new DoctrineORMAdapter($qb->getQuery(), true, false);
        $pagerFanta = new Pagerfanta($pagerAdapter);
        $pagerFanta->setMaxPerPage(50);

        try {
            $pagerFanta->setCurrentPage($page);
        } catch (OutOfRangeCurrentPageException $e) {
            if ($page > 1) {
                return $this->redirect($this->generateUrl('user_index', ['page' => $pagerFanta->getNbPages()]), 302);
            }
        }

        return $this->render('WallabagUserBundle:Manage:index.html.twig', array(
            'users' => $pagerFanta,
        ));
    }

    /**
     * Creates a new User entity.
     *
     * @Route("/new", name="user_new")
     * @Method({"GET", "POST"})
     */
    public function newAction(Request $request)
    {
        $userManager = $this->container->get('fos_user.user_manager');

        $user = $userManager->createUser();
        // enable created user by default
        $user->setEnabled(true);

        $form = $this->createForm('Wallabag\UserBundle\Form\NewUserType', $user, [
            'validation_groups' => ['Profile'],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $userManager->updateUser($user);

            // dispatch a created event so the associated config will be created
            $event = new UserEvent($user, $request);
            $this->get('event_dispatcher')->dispatch(FOSUserEvents::USER_CREATED, $event);

            $this->get('session')->getFlashBag()->add(
                'notice',
                $this->get('translator')->trans('flashes.user.notice.added', ['%username%' => $user->getUsername()])
            );

            return $this->redirectToRoute('user_edit', array('id' => $user->getId()));
        }

        return $this->render('WallabagUserBundle:Manage:new.html.twig', array(
            'user' => $user,
            'form' => $form->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing User entity.
     *
     * @Route("/{id}/edit", name="user_edit")
     * @Method({"GET", "POST"})
     */
    public function editAction(Request $request, User $user)
    {
        $deleteForm = $this->createDeleteForm($user);
        $editForm = $this->createForm('Wallabag\UserBundle\Form\UserType', $user);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($user);
            $em->flush();

            $this->get('session')->getFlashBag()->add(
                'notice',
                $this->get('translator')->trans('flashes.user.notice.updated', ['%username%' => $user->getUsername()])
            );

            return $this->redirectToRoute('user_edit', array('id' => $user->getId()));
        }

        return $this->render('WallabagUserBundle:Manage:edit.html.twig', array(
            'user' => $user,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
            'twofactor_auth' => $this->getParameter('twofactor_auth'),
        ));
    }

    /**
     * Deletes a User entity.
     *
     * @Route("/{id}", name="user_delete")
     * @Method("DELETE")
     */
    public function deleteAction(Request $request, User $user)
    {
        $form = $this->createDeleteForm($user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->get('session')->getFlashBag()->add(
                'notice',
                $this->get('translator')->trans('flashes.user.notice.deleted', ['%username%' => $user->getUsername()])
            );

            $em = $this->getDoctrine()->getManager();
            $em->remove($user);
            $em->flush();
        }

        return $this->redirectToRoute('user_index');
    }

    /**
     * Creates a form to delete a User entity.
     *
     * @param User $user The User entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm(User $user)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('user_delete', array('id' => $user->getId())))
            ->setMethod('DELETE')
            ->getForm()
        ;
    }

    /**
     * @param Request $request
     * @param int     $page
     *
     * @Route("/search/{page}", name="user-search", defaults={"page" = 1})
     *
     * Default parameter for page is hardcoded (in duplication of the defaults from the Route)
     * because this controller is also called inside the layout template without any page as argument
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function searchFormAction(Request $request, $page = 1, $currentRoute = null)
    {
        // fallback to retrieve currentRoute from query parameter instead of injected one (when using inside a template)
        if (null === $currentRoute && $request->query->has('currentRoute')) {
            $currentRoute = $request->query->get('currentRoute');
        }

        $form = $this->createForm(SearchUserType::class);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->get('logger')->info('searching users');
            $em = $this->getDoctrine()->getManager();

            $searchTerm = (isset($request->get('search_user')['term']) ? $request->get('search_user')['term'] : '');

            $qb = $em->getRepository('WallabagUserBundle:User')->getQueryBuilderForSearch($searchTerm);

            $pagerAdapter = new DoctrineORMAdapter($qb->getQuery(), true, false);
            $pagerFanta = new Pagerfanta($pagerAdapter);
            $pagerFanta->setMaxPerPage(50);

            try {
                $pagerFanta->setCurrentPage($page);
            } catch (OutOfRangeCurrentPageException $e) {
                if ($page > 1) {
                    return $this->redirect($this->generateUrl('user_index', ['page' => $pagerFanta->getNbPages()]), 302);
                }
            }

            return $this->render('WallabagUserBundle:Manage:index.html.twig', array(
                'users' => $pagerFanta,
            ));
        }

        return $this->render('WallabagUserBundle:Manage:search_form.html.twig', [
            'form' => $form->createView(),
            'currentRoute' => $currentRoute,
        ]);
    }
}
