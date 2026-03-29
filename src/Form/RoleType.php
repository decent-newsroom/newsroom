<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;


class RoleType extends AbstractType
{

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->setAction('/admin/role/add')
            ->add('role', TextType::class, [
                'required' => true,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Add Role',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
    }
}
