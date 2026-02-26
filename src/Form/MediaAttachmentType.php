<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\MediaAttachment;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class MediaAttachmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('url', UrlType::class, [
                'label' => 'URL',
                'required' => true,
                'attr' => [
                    'class' => 'form-control media-attachment-url',
                    'placeholder' => 'https://example.com/media/file.mp3',
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'URL is required']),
                    new Assert\Url(['message' => 'Must be a valid URL']),
                ],
            ])
            ->add('mimeType', TextType::class, [
                'label' => 'MIME type',
                'required' => true,
                'attr' => [
                    'class' => 'form-control media-attachment-mime',
                    'placeholder' => 'audio/mpeg',
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'MIME type is required']),
                    new Assert\Regex([
                        'pattern' => '/^[\w\-]+\/[\w\-\+\.]+$/',
                        'message' => 'Must be a valid MIME type (e.g., audio/mpeg, image/png)',
                    ]),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MediaAttachment::class,
        ]);
    }
}

