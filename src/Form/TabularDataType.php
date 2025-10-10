<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TabularDataType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Title',
                'required' => true,
            ])
            ->add('csvContent', TextareaType::class, [
                'label' => 'CSV Content',
                'required' => true,
                'attr' => ['rows' => 10, 'placeholder' => 'date,hashrate\n2025-10-01,795\n2025-10-02,802'],
            ])
            ->add('license', TextType::class, [
                'label' => 'License (optional)',
                'required' => false,
            ])
            ->add('units', TextType::class, [
                'label' => 'Units (optional, e.g., col=2,EH/s)',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Configure your form options here
        ]);
    }
}
