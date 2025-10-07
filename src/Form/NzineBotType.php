<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class NzineBotType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isDisabled = $options['disabled'] ?? false;

        $builder
            ->add('name', TextType::class, [
                'required' => true,
                'label' => 'N-Zine Name',
                'help' => 'The name of your N-Zine publication',
                'disabled' => $isDisabled
            ])
            ->add('about', TextareaType::class, [
                'required' => false,
                'label' => 'Description',
                'help' => 'Describe what this N-Zine is about',
                'disabled' => $isDisabled
            ])
            ->add('feedUrl', TextType::class, [
                'required' => false,
                'label' => 'RSS Feed URL',
                'help' => 'Optional: Add an RSS/Atom feed URL to automatically fetch and publish articles',
                'attr' => [
                    'placeholder' => 'https://example.com/feed.rss'
                ],
                'disabled' => $isDisabled
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Create N-Zine',
                'disabled' => $isDisabled,
                'attr' => [
                    'class' => 'btn btn-primary',
                    'title' => $isDisabled ? 'Please login to create an N-Zine' : ''
                ]
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
    }
}
