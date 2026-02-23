<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\MagazineDraft;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form type for the categories step of the magazine wizard.
 * Handles a collection of CategoryType entries.
 */
class MagazineCategoriesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('categories', CollectionType::class, [
                'entry_type' => CategoryType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => false,
                'entry_options' => [
                    'user_lists' => $options['user_lists'],
                ],
            ]);

        // Filter out empty categories before validation
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            if (!is_array($data)) {
                $data = [];
            }
            if (isset($data['categories']) && is_array($data['categories'])) {
                $data['categories'] = array_values(array_filter($data['categories'], function ($cat) {
                    $hasTitle = !empty($cat['title']);
                    $hasCoordinate = !empty($cat['existingListCoordinate']);
                    return $hasTitle || $hasCoordinate;
                }));
            } else {
                $data['categories'] = [];
            }
            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MagazineDraft::class,
            'user_lists' => [],
        ]);
    }
}

