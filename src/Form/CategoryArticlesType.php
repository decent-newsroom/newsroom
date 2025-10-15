<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\CategoryDraft;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CategoryArticlesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Category',
                'required' => true,
                'attr' => ['readonly' => true],
            ])
            ->add('articles', CollectionType::class, [
                'entry_type' => TextType::class,
                'entry_options' => [
                    'required' => false,
                    'attr' => [
                        'placeholder' => '30023:pubkey:slug'
                    ],
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => 'Article coordinates (kind:pubkey:slug)',
                'prototype' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CategoryDraft::class,
        ]);
    }
}
