<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\CategoryDraft;
use App\Form\DataTransformer\CommaSeparatedToArrayTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CategoryType extends AbstractType
{
    public function __construct(private CommaSeparatedToArrayTransformer $transformer)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Category title',
                'required' => true,
            ])
            ->add('summary', TextType::class, [
                'label' => 'Summary',
                'required' => false,
            ])
            ->add('tags', TextType::class, [
                'label' => 'Tags (comma separated)',
                'required' => false,
            ]);

        $builder->get('tags')->addModelTransformer($this->transformer);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CategoryDraft::class,
        ]);
    }
}

