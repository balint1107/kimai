<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\User;
use App\Form\UserPasswordType;
use App\ShiftAccounting\ShiftAccountService;
use App\User\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Integrated account screens; form CSRF and native voters apply without the upstream management frontend. */
#[Route(path: '/shifts/account')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ShiftAccountController extends AbstractController
{
    /** Uses $em for account lookup, $accounts for guarded lifecycle and $users for native password hashing. */
    public function __construct(private EntityManagerInterface $em, private ShiftAccountService $accounts, private UserService $users)
    {
    }

    /** Lists accounts for administrators without exposing shifts, rates, credentials or Google tokens. */
    #[Route(path: '/users', name: 'shift_users', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    #[IsGranted('view_user')]
    public function index(): Response
    {
        return $this->render('shifts/accounts/list.html.twig', ['tab' => 'users', 'users' => $this->em->getRepository(User::class)->findBy([], ['username' => 'ASC'])]);
    }

    /** Creates an ordinary or explicitly authorized administrator account from CSRF-protected $request. */
    #[Route(path: '/users/new', name: 'shift_user_create', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): Response
    {
        return $this->editAccount($request, $this->accounts->create());
    }

    /** Edits the account selected by $id using native per-user permissions; never deletes retained history. */
    #[Route(path: '/users/{id}', name: 'shift_user_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, int $id): Response
    {
        $user = $this->em->find(User::class, $id);
        if ($user === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted('edit', $user);

        return $this->editAccount($request, $user);
    }

    /** Updates only the current user's display name/email from $request; username, roles and activation are not writable. */
    #[Route(path: '', name: 'shift_account', methods: ['GET', 'POST'])]
    public function profile(Request $request): Response
    {
        $user = $this->getUser();
        $form = $this->createFormBuilder($user, ['validation_groups' => ['Default', 'Profile'], 'csrf_token_id' => 'shift_profile'])
            ->add('username', TextType::class, ['label' => 'username', 'disabled' => true])
            ->add('email', EmailType::class, ['label' => 'email'])
            ->add('alias', TextType::class, ['label' => 'name', 'required' => false])->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->accounts->save($user, $user);
            $this->addFlash('success', 'shift.account_saved');

            return $this->redirectToRoute('shift_account');
        }

        return $this->render('shifts/accounts/form.html.twig', ['tab' => 'account', 'title' => 'profile.title', 'form' => $form->createView(), 'account' => $user, 'manage' => false]);
    }

    /** Changes the current user's password from $request, requiring the old password unless native recovery requires a reset. */
    #[Route(path: '/password', name: 'shift_account_password', methods: ['GET', 'POST'])]
    public function password(Request $request): Response
    {
        $user = $this->getUser();
        $this->denyAccessUnlessGranted('password', $user);
        $form = $this->createForm(UserPasswordType::class, $user, ['require_current_password' => !$user->requiresPasswordReset(), 'deny_current_password' => true]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $user->setRequiresPasswordReset(false);
            $this->users->saveUser($user);
            $request->getSession()->migrate(true);
            $this->addFlash('success', 'shift.account_saved');

            return $this->redirectToRoute('shift_account');
        }

        return $this->render('shifts/accounts/form.html.twig', ['tab' => 'account', 'title' => 'password', 'form' => $form->createView(), 'account' => $user, 'manage' => false]);
    }

    /** Builds a narrow account form for $user and handles $request; invalid input never flushes partially edited users. */
    private function editAccount(Request $request, User $user): Response
    {
        $new = $user->getId() === null;
        $self = !$new && $user->getId() === $this->getUser()->getId();
        $form = $this->createFormBuilder($user, ['csrf_token_id' => 'shift_user', 'validation_groups' => static fn (FormInterface $form) => array_merge(['Default', $new ? 'UserCreate' : 'Profile'], $form->get('plainPassword')->getData() !== null && $form->get('plainPassword')->getData() !== '' ? ['PasswordUpdate'] : [])])
            ->add('username', TextType::class, ['label' => 'username'])
            ->add('email', EmailType::class, ['label' => 'email'])
            ->add('alias', TextType::class, ['label' => 'name', 'required' => false])
            ->add('plainPassword', RepeatedType::class, ['type' => PasswordType::class, 'required' => $new, 'first_options' => ['label' => 'password', 'attr' => ['autocomplete' => 'new-password']], 'second_options' => ['label' => 'password_repeat', 'attr' => ['autocomplete' => 'new-password']], 'disabled' => $self || (!$new && !$this->isGranted('password', $user))])
            ->add('enabled', CheckboxType::class, ['label' => 'active', 'required' => false, 'disabled' => $self])
            ->add('administrator', CheckboxType::class, ['mapped' => false, 'label' => 'shift.account_admin', 'required' => false, 'data' => $user->isAdmin(false), 'disabled' => $self || $user->isSuperAdmin() || !$this->getUser()->canAssignRole(User::ROLE_ADMIN) || !$this->isGranted('roles', $user)])
            ->add('reset', CheckboxType::class, ['mapped' => false, 'label' => 'shift.account_require_password', 'required' => false, 'data' => $user->requiresPasswordReset(), 'disabled' => $self || (!$new && !$this->isGranted('password', $user))])->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->accounts->save($this->getUser(), $user, (bool) $form->get('administrator')->getData(), $self || !$this->isGranted('password', $user) ? null : (bool) $form->get('reset')->getData());
                $this->addFlash('success', 'shift.account_saved');

                return $this->redirectToRoute('shift_users');
            } catch (\App\Validator\ValidationFailedException) {
                $form->addError(new FormError('shift.validation_error'));
            }
        }

        return $this->render('shifts/accounts/form.html.twig', ['tab' => 'users', 'title' => $new ? 'shift.account_new' : 'shift.account_edit', 'form' => $form->createView(), 'account' => $user, 'manage' => true]);
    }
}
