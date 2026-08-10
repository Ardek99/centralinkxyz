<?php

namespace App\Controller\Admin;

use App\Entity\Request;
use App\Enum\CryptoType;
use App\Enum\RequestType;
use App\Service\FundsCalculator;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

class RequestCrudController extends AbstractCrudController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AdminUrlGenerator $adminUrlGenerator,
        private FundsCalculator $fundsCalculator
    ) {}

    public static function getEntityFqcn(): string
    {
        return Request::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Demande')
            ->setEntityLabelInPlural('Demandes')
            ->setPageTitle('index', 'Gestion des Demandes')
            ->setPageTitle('detail', fn (Request $r) => sprintf('%s - %s %s', $r->getType()?->label() ?? 'N/A', $r->getAmount(), $r->getUser()?->getFullName()))
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        
        yield AssociationField::new('user', 'Utilisateur')
            ->setFormTypeOption('choice_label', 'fullName');
        
        yield ChoiceField::new('type', 'Type')
            ->setChoices([
                'Dépôt' => RequestType::DEPOSIT,
                'Retrait' => RequestType::WITHDRAWAL,
            ]);
        
        yield ChoiceField::new('cryptoType', 'Crypto')
            ->setChoices([
                'Bitcoin' => CryptoType::BTC,
                'Ethereum' => CryptoType::ETH,
                'USD Coin' => CryptoType::USDC,
                'Tether' => CryptoType::USDT
            ]);
        
        yield NumberField::new('amount', 'Montant')
            ->setNumDecimals(8);
        
        yield TextField::new('publicAddress', 'Adresse publique')
            ->hideOnIndex()
            ->setHelp('Uniquement pour les retraits');
        
        yield BooleanField::new('isValidated', 'Validée')
            ->renderAsSwitch(true);
        
        yield DateTimeField::new('validatedAt', 'Validée le')
            ->setFormat('dd/MM/yyyy HH:mm')
            ->hideOnForm()
            ->hideOnIndex();
        
        yield DateTimeField::new('createdAt', 'Créée le')
            ->setFormat('dd/MM/yyyy HH:mm')
            ->hideOnForm();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(BooleanFilter::new('isValidated', 'Validée'))
            ->add('type')
            ->add('user');
    }

    public function configureActions(Actions $actions): Actions
    {
        $validateAction = Action::new('validate', 'Valider', 'fa fa-check')
            ->linkToCrudAction('validateRequest')
            ->setCssClass('btn btn-success')
            ->displayIf(fn (Request $r) => !$r->isValidated());
        
        $rejectAction = Action::new('reject', 'Rejeter', 'fa fa-times')
            ->linkToCrudAction('rejectRequest')
            ->setCssClass('btn btn-danger')
            ->displayIf(fn (Request $r) => !$r->isValidated());

        return $actions
            ->add(Crud::PAGE_INDEX, $validateAction)
            ->add(Crud::PAGE_INDEX, $rejectAction)
            ->add(Crud::PAGE_DETAIL, $validateAction)
            ->add(Crud::PAGE_DETAIL, $rejectAction)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->reorder(Crud::PAGE_INDEX, [Action::DETAIL, 'validate', 'reject', Action::EDIT, Action::DELETE]);
    }

    public function validateRequest(AdminContext $context): Response
    {
        /** @var Request $request */
        $request = $context->getEntity()->getInstance();

        // Un retrait ne doit jamais être validé s'il dépasse les fonds
        // réellement disponibles de l'utilisateur à cet instant (d'autres
        // demandes ont pu être validées entre-temps).
        if ($request->getType() === RequestType::WITHDRAWAL) {
            $availableFunds = $this->fundsCalculator->getAvailableFunds($request->getUser());
            $requestedAmount = (float) $request->getAmount();

            if ($requestedAmount > $availableFunds) {
                $this->addFlash('error', sprintf(
                    'Impossible de valider : %s ne dispose que de $%s disponibles pour un retrait de $%s.',
                    $request->getUser()?->getFullName(),
                    number_format($availableFunds, 2),
                    number_format($requestedAmount, 2)
                ));

                return $this->redirect(
                    $this->adminUrlGenerator
                        ->setController(self::class)
                        ->setAction(Action::INDEX)
                        ->generateUrl()
                );
            }
        }

        $request->setIsValidated(true);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf(
            'Demande #%d validée avec succès !',
            $request->getId()
        ));

        return $this->redirect(
            $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->generateUrl()
        );
    }

    public function rejectRequest(AdminContext $context): Response
    {
        /** @var Request $request */
        $request = $context->getEntity()->getInstance();
        
        $this->entityManager->remove($request);
        $this->entityManager->flush();
        
        $this->addFlash('warning', sprintf(
            'Demande #%d rejetée et supprimée.',
            $request->getId()
        ));

        return $this->redirect(
            $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->generateUrl()
        );
    }
}


