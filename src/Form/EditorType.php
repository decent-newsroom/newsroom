<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Article;
use App\Form\DataTransformer\CommaSeparatedToJsonTransformer;
use App\Form\Type\QuillType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EditorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('slug', TextType::class, [
                'required' => false,
                'help' => 'Leave empty to auto-generate from title. When editing an existing article, changing the slug will fork the article.',
                'sanitize_html' => true,
                'attr' => ['placeholder' => 'decent-article-slug', 'class' => 'form-control']
            ])
            ->add('title', TextType::class, [
                'required' => true,
                'sanitize_html' => true,
                'attr' => ['placeholder' => 'Awesome title', 'class' => 'form-control']])
            ->add('summary', TextareaType::class, [
                'required' => false,
                'sanitize_html' => true,
                'attr' => ['class' => 'form-control']])
            ->add('content', TextareaType::class, [
                'required' => true,
                'label' => 'Markdown Content',
                'attr' => ['placeholder' => 'Write Markdown content', 'class' => 'form-control editor-md-pane']
            ])
            ->add('content_html', QuillType::class, [
                'required' => false,
                'mapped' => false,
                'label' => 'HTML Content (Quill)',
                'attr' => ['placeholder' => 'Write content', 'class' => 'form-control editor-quill-pane']
            ])
            ->add('image', UrlType::class, [
                'required' => false,
                'label' => 'Cover image URL',
                'attr' => ['class' => 'form-control']])
            ->add('topics', TextType::class, [
                'required' => false,
                'sanitize_html' => true,
                'help' => 'Separate tags with commas, skip #',
                'attr' => ['placeholder' => 'philosophy, nature, economics', 'class' => 'form-control']])
            ->add('clientTag', CheckboxType::class, [
                'label'    => 'Add client tag to article (Decent Newsroom)',
                'required' => false,
                'mapped'   => false,
                'data'    => true,
            ])
            ->add('isDraft', CheckboxType::class, [
                'label'    => 'Save as draft',
                'required' => false,
            ])
            ->add('advancedMetadata', AdvancedMetadataType::class, [
                'label' => false,
                'required' => false,
                'mapped' => false,
            ])
            ->add('contentDelta', HiddenType::class, [
                'required' => false,
                'mapped' => false,
                'attr' => ['type' => 'hidden'],
            ])
            ->add('contentNMD', HiddenType::class, [
                'required' => false,
                'mapped' => false,
                'attr' => ['type' => 'hidden'],
            ])
            // user's pubkey
            ->add('pubkey', HiddenType::class, [
                'required' => false,
                'mapped' => false,
                'attr' => ['type' => 'hidden'],
            ])
            // log in method, can be extension or bunker
            ->add('loginMethod', HiddenType::class, [
                'required' => false,
                'mapped' => false,
                'attr' => ['type' => 'hidden'],
            ])
        ;

        // Apply the custom transformer
        $builder->get('topics')
            ->addModelTransformer(new CommaSeparatedToJsonTransformer());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Article::class,
        ]);
    }
}
