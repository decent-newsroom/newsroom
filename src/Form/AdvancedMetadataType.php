<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\AdvancedMetadata;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AdvancedMetadataType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('doNotRepublish', CheckboxType::class, [
                'label' => 'Do not republish',
                'required' => false,
                'help' => 'Mark this article with a policy label indicating it should not be republished',
                'row_attr' => ['class' => 'form-check'],
                'label_attr' => ['class' => 'form-check-label'],
                'attr' => ['class' => 'form-check-input'],
            ])
            ->add('license', ChoiceType::class, [
                'label' => 'License',
                'required' => false,
                'choices' => [
                    'No license' => '',
                    'Public Domain (CC0)' => 'CC0-1.0',
                    'Attribution (CC-BY)' => 'CC-BY-4.0',
                    'Attribution-ShareAlike (CC-BY-SA)' => 'CC-BY-SA-4.0',
                    'Attribution-NonCommercial (CC-BY-NC)' => 'CC-BY-NC-4.0',
                    'Attribution-NonCommercial-ShareAlike (CC-BY-NC-SA)' => 'CC-BY-NC-SA-4.0',
                    'Attribution-NoDerivs (CC-BY-ND)' => 'CC-BY-ND-4.0',
                    'Attribution-NonCommercial-NoDerivs (CC-BY-NC-ND)' => 'CC-BY-NC-ND-4.0',
                    'MIT License' => 'MIT',
                    'Apache License 2.0' => 'Apache-2.0',
                    'GNU GPL v3' => 'GPL-3.0',
                    'GNU AGPL v3' => 'AGPL-3.0',
                    'All rights reserved' => 'All rights reserved',
                    'Custom license' => 'custom',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('customLicense', TextType::class, [
                'label' => 'Custom license identifier',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'e.g., My-Custom-License-1.0'
                ],
                'help' => 'Specify a custom SPDX identifier or license name',
            ])
            ->add('zapSplits', CollectionType::class, [
                'entry_type' => ZapSplitType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => 'Zap splits',
                'required' => false,
                'attr' => [
                    'class' => 'zap-splits-collection',
                ],
                'help' => 'Configure multiple recipients for zaps (tips). Leave weights empty for equal distribution.',
            ])
            ->add('contentWarning', TextType::class, [
                'label' => 'Content warning',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'e.g., graphic content, spoilers, etc.'
                ],
                'help' => 'Optional warning about sensitive content',
            ])
            ->add('expirationTimestamp', DateTimeType::class, [
                'label' => 'Expiration date',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'timestamp',
                'attr' => ['class' => 'form-control'],
                'help' => 'When this article should expire (optional)',
            ])
            ->add('isProtected', CheckboxType::class, [
                'label' => 'Protected event',
                'required' => false,
                'help' => 'Mark this event as protected. Warning: Some relays may reject protected events.',
                'row_attr' => ['class' => 'form-check'],
                'label_attr' => ['class' => 'form-check-label'],
                'attr' => ['class' => 'form-check-input'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdvancedMetadata::class,
        ]);
    }
}

