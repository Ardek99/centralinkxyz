<?php

namespace App\Controller\Admin;

use App\Entity\Request;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\RequestRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private RequestRepository $requestRepository
    ) {}

    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        // Afficher un vrai dashboard au lieu d'une redirection
        $pendingCount = $this->requestRepository->countPending();

        return $this->render('admin/dashboard.html.twig', [
            'pending_count' => $pendingCount,
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('🔗 Centralink Admin')
            ->setFaviconPath('data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 128 128%22><text y=%221.2em%22 font-size=%2296%22>🔗</text></svg>')
            ->setLocales(['fr']);
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        
        yield MenuItem::section('Validation');
        yield MenuItem::linkToCrud('Demandes à valider', 'fa fa-clock', Request::class)
            ->setQueryParameter('filters[isValidated]', '0');
        
        yield MenuItem::section('Gestion');
        yield MenuItem::linkToCrud('Toutes les demandes', 'fa fa-credit-card', Request::class);
        yield MenuItem::linkToCrud('Toutes les transactions', 'fa fa-exchange-alt', Transaction::class);
        yield MenuItem::linkToCrud('Utilisateurs', 'fa fa-users', User::class);
        
        yield MenuItem::section('');
        yield MenuItem::linkToRoute('Retour aux transactions', 'fa fa-arrow-left', 'app_transaction_index');
    }
}
