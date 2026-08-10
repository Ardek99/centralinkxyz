<?php

namespace App\Form;

use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\CryptoType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TransactionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('user', EntityType::class, [
                'label' => 'Client',
                'class' => User::class,
                'choice_label' => 'fullName',
                'placeholder' => 'Sélectionner un client',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('cryptoType', ChoiceType::class, [
                'label' => 'Crypto',
                'choices' => array_combine(
                    array_map(fn(CryptoType $type) => $type->label(), CryptoType::cases()),
                    CryptoType::cases()
                ),
                'placeholder' => 'Sélectionner une crypto',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('blockchainTxHash', TextType::class, [
                'label' => 'Hash de transaction BTC (optionnel)',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'Ex: a9a84ac2d662a3f9c3209cbce786167e05b686bc4c49457670f31c2ad4cd077f',
                ],
            ])
            ->add('entryPrice', NumberType::class, [
                'label' => 'Prix d\'entrée ($)',
                'scale' => 8,
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'Ex: 42000.00',
                    'step' => 'any',
                ],
            ])
            ->add('usdAmount', NumberType::class, [
                'label' => 'Montant investi ($)',
                'mapped' => false,
                'scale' => 2,
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'Ex: 1000',
                    'step' => 'any',
                ],
            ])
            ->add('transactionDate', DateTimeType::class, [
                'label' => 'Date de la transaction',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-input'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Transaction::class,
        ]);
    }
}

