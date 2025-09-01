<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\CategoryDraft;
use App\Dto\MagazineDraft;
use App\Form\DataTransformer\CommaSeparatedToArrayTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MagazineSetupType extends AbstractType
{
    public function __construct(private CommaSeparatedToArrayTransformer $transformer)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Magazine title',
                'required' => true,
            ])
            ->add('summary', TextType::class, [
                'label' => 'Description / summary',
                'required' => true,
            ])
            ->add('imageUrl', TextType::class, [
                'label' => 'Logo / image URL',
                'required' => false,
            ])
            ->add('language', TextType::class, [
                'label' => 'Language (optional)',
                'required' => false,
            ])
            ->add('tags', TextType::class, [
                'label' => 'Tags (comma separated, optional)',
                'required' => false,
            ])
            ->add('categories', CollectionType::class, [
                'entry_type' => CategoryType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => false,
            ])
        ;

        $builder->get('tags')->addModelTransformer($this->transformer);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MagazineDraft::class,
        ]);
    }
}

