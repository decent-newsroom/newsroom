<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Entity\User;
use App\Enum\RolesEnum;
use App\Form\RoleType;
use App\Repository\UserEntityRepository;
use App\Service\MutedPubkeysService;
use App\Service\RedisCacheService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class RoleController extends AbstractController
{
    #[Route('/admin/role', name: 'admin_roles')]
    public function index(UserEntityRepository $userRepository, RedisCacheService $redisCacheService): Response
    {
        $form = $this->createForm(RoleType::class);

        // Get featured writers for display
        $featuredWriters = $userRepository->findFeaturedWriters();
        $featuredWritersData = $this->enrichUsersWithMetadata($featuredWriters, $redisCacheService);

        // Get muted users for display
        $mutedUsers = $userRepository->findMutedUsers();
        $mutedUsersData = $this->enrichUsersWithMetadata($mutedUsers, $redisCacheService);

        return $this->render('admin/roles.html.twig', [
            'form' => $form->createView(),
            'featuredWriters' => $featuredWritersData,
            'mutedUsers' => $mutedUsersData,
        ]);
    }

    /**
     * Add a role to current user as submitted in a form
     */
    #[Route('/admin/role/add', name: 'admin_roles_add')]
    public function addRole(Request $request, UserEntityRepository $userRepository, EntityManagerInterface $em, TokenStorageInterface $tokenStorage, RedisCacheService $redisCacheService): Response
    {
        // get role from request and add to current user's roles and save to db
        $npub = $this->getUser()->getUserIdentifier();

        $form = $this->createForm(RoleType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $featuredWriters = $userRepository->findFeaturedWriters();
            $featuredWritersData = $this->enrichUsersWithMetadata($featuredWriters, $redisCacheService);
            return $this->render('admin/roles.html.twig', [
                'form' => $form->createView(),
                'featuredWriters' => $featuredWritersData,
            ]);
        }

        $role = $form->get('role')->getData();

        if (!$role || !str_starts_with($role, 'ROLE_')) {
            $this->addFlash('error', 'Invalid role format');
            return $this->redirectToRoute('admin_roles');
        }

        $user = $userRepository->findOneBy(['npub' => $npub]);
        $user->addRole($role);
        $em->persist($user);
        $em->flush();

        // regenerate token with new roles
        // Refresh the user token after update
        $token = $tokenStorage->getToken();
        if ($token) {
            $token->setUser($user);
            $tokenStorage->setToken($token);
        }

        // add a flash message
        $this->addFlash('success', 'Role added to user');

        $featuredWriters = $userRepository->findFeaturedWriters();
        $featuredWritersData = $this->enrichUsersWithMetadata($featuredWriters, $redisCacheService);

        return $this->render('admin/roles.html.twig', [
            'form' => $form->createView(),
            'featuredWriters' => $featuredWritersData,
        ]);
    }

    /**
     * Remove a role from current user
     */
    #[Route('/admin/role/remove', name: 'admin_roles_remove', methods: ['POST'])]
    public function removeOwnRole(Request $request, UserEntityRepository $userRepository, EntityManagerInterface $em, TokenStorageInterface $tokenStorage): Response
    {
        $npub = $this->getUser()->getUserIdentifier();
        $role = $request->request->get('role');

        if (!$role) {
            $this->addFlash('error', 'Invalid role');
            return $this->redirectToRoute('admin_roles');
        }

        // Prevent removing ROLE_ADMIN from yourself
        if ($role === 'ROLE_ADMIN') {
            $this->addFlash('error', 'Cannot remove ROLE_ADMIN from yourself');
            return $this->redirectToRoute('admin_roles');
        }

        $user = $userRepository->findOneBy(['npub' => $npub]);
        if (!$user) {
            $this->addFlash('error', 'User not found');
            return $this->redirectToRoute('admin_roles');
        }

        $user->removeRole($role);
        $em->flush();

        // Refresh the user token after update
        $token = $tokenStorage->getToken();
        if ($token) {
            $token->setUser($user);
            $tokenStorage->setToken($token);
        }

        $this->addFlash('success', 'Role removed');

        return $this->redirectToRoute('admin_roles');
    }

    #[Route('/admin/featured-writers/add', name: 'admin_featured_writers_add', methods: ['POST'])]
    public function addFeaturedWriter(Request $request, UserEntityRepository $userRepository, EntityManagerInterface $em): Response
    {
        $npub = $request->request->get('npub');

        if (!$npub || !str_starts_with($npub, 'npub1')) {
            $this->addFlash('error', 'Invalid npub format');
            return $this->redirectToRoute('admin_roles');
        }

        $user = $userRepository->findOneBy(['npub' => $npub]);

        if (!$user) {
            // Create user if not exists
            $user = new User();
            $user->setNpub($npub);
            $user->setRoles([RolesEnum::FEATURED_WRITER]);
            $em->persist($user);
            $this->addFlash('success', 'Created new user and added as featured writer');
        } else {
            if ($user->isFeaturedWriter()) {
                $this->addFlash('warning', 'User is already a featured writer');
                return $this->redirectToRoute('admin_roles');
            }
            $user->addRole(RolesEnum::FEATURED_WRITER->value);
            $this->addFlash('success', 'User added as featured writer');
        }

        $em->flush();

        return $this->redirectToRoute('admin_roles');
    }

    #[Route('/admin/featured-writers/remove/{id}', name: 'admin_featured_writers_remove', methods: ['POST'])]
    public function removeFeaturedWriter(int $id, UserEntityRepository $userRepository, EntityManagerInterface $em): Response
    {
        $user = $userRepository->find($id);

        if (!$user) {
            $this->addFlash('error', 'User not found');
            return $this->redirectToRoute('admin_roles');
        }

        $user->removeRole(RolesEnum::FEATURED_WRITER->value);
        $em->flush();

        $this->addFlash('success', 'User removed from featured writers');

        return $this->redirectToRoute('admin_roles');
    }

    #[Route('/admin/muted-users/add', name: 'admin_muted_users_add', methods: ['POST'])]
    public function addMutedUser(Request $request, UserEntityRepository $userRepository, EntityManagerInterface $em, MutedPubkeysService $mutedPubkeysService): Response
    {
        $npub = $request->request->get('npub');

        if (!$npub || !str_starts_with($npub, 'npub1')) {
            $this->addFlash('error', 'Invalid npub format');
            return $this->redirectToRoute('admin_roles');
        }

        $user = $userRepository->findOneBy(['npub' => $npub]);

        if (!$user) {
            // Create user if not exists
            $user = new User();
            $user->setNpub($npub);
            $user->setRoles([RolesEnum::MUTED->value]);
            $em->persist($user);
            $this->addFlash('success', 'Created new user and added to muted list');
        } else {
            if ($user->isMuted()) {
                $this->addFlash('warning', 'User is already muted');
                return $this->redirectToRoute('admin_roles');
            }
            $user->addRole(RolesEnum::MUTED->value);
            $this->addFlash('success', 'User added to muted list');
        }

        $em->flush();

        // Refresh the muted pubkeys cache
        $mutedPubkeysService->refreshCache();

        return $this->redirectToRoute('admin_roles');
    }

    #[Route('/admin/muted-users/remove/{id}', name: 'admin_muted_users_remove', methods: ['POST'])]
    public function removeMutedUser(int $id, UserEntityRepository $userRepository, EntityManagerInterface $em, MutedPubkeysService $mutedPubkeysService): Response
    {
        $user = $userRepository->find($id);

        if (!$user) {
            $this->addFlash('error', 'User not found');
            return $this->redirectToRoute('admin_roles');
        }

        $user->removeRole(RolesEnum::MUTED->value);
        $em->flush();

        // Refresh the muted pubkeys cache
        $mutedPubkeysService->refreshCache();

        $this->addFlash('success', 'User removed from muted list');

        return $this->redirectToRoute('admin_roles');
    }

    /**
     * Enrich user array with Nostr metadata
     * @param User[] $users
     * @return array
     */
    private function enrichUsersWithMetadata(array $users, RedisCacheService $redisCacheService): array
    {
        if (empty($users)) {
            return [];
        }

        $hexPubkeys = [];
        $npubToHex = [];
        foreach ($users as $user) {
            $npub = $user->getNpub();
            if (NostrKeyUtil::isNpub($npub)) {
                $hex = NostrKeyUtil::npubToHex($npub);
                $hexPubkeys[] = $hex;
                $npubToHex[$npub] = $hex;
            }
        }

        $metadataMap = [];
        if (!empty($hexPubkeys)) {
            $metadataMap = $redisCacheService->getMultipleMetadata($hexPubkeys);
        }

        $result = [];
        foreach ($users as $user) {
            $npub = $user->getNpub();
            $hex = $npubToHex[$npub] ?? null;
            $result[] = [
                'user' => $user,
                'npub' => $npub,
                'metadata' => $hex ? ($metadataMap[$hex] ?? null) : null,
            ];
        }

        return $result;
    }
}
