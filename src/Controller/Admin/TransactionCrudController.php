<?php

namespace App\Controller\Admin;

use App\Entity\Transaction;
use App\Enum\CryptoType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;

class TransactionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Transaction::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Transaction')
            ->setEntityLabelInPlural('Transactions')
            ->setPageTitle('index', 'Gestion des Transactions')
            ->setPageTitle('detail', fn (Transaction $t) => sprintf('%s - %s %s', $t->getCryptoType()?->symbol() ?? 'N/A', $t->getAmount(), $t->getUser()?->getFullName()))
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        // La création se fait uniquement via /transactions/new (formulaire dédié au trading)
        return $actions
            ->disable(Action::NEW)
            ->disable(Action::EDIT);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield AssociationField::new('user', 'Utilisateur')
            ->setFormTypeOption('choice_label', 'fullName');

        yield ChoiceField::new('cryptoType', 'Crypto')
            ->setChoices([
                'Bitcoin' => CryptoType::BTC,
                'Ethereum' => CryptoType::ETH,
                'USD Coin' => CryptoType::USDC,
                'Tether' => CryptoType::USDT
            ]);

        yield NumberField::new('amount', 'Montant')
            ->setNumDecimals(8);

        yield NumberField::new('entryPrice', 'Prix entrée ($)')
            ->setNumDecimals(2);

        yield NumberField::new('exitPrice', 'Prix sortie ($)')
            ->setNumDecimals(2)
            ->hideOnIndex();

        yield DateTimeField::new('transactionDate', 'Date transaction')
            ->setFormat('dd/MM/yyyy HH:mm');

        yield DateTimeField::new('createdAt', 'Créée le')
            ->setFormat('dd/MM/yyyy HH:mm')
            ->hideOnForm();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('user');
    }
}
