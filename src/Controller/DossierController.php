<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dossier\ConsultationExporter;
use App\Dossier\TranscriptView;
use App\Entity\Dossier;
use App\Repository\DossierRepository;
use App\Research\Consultation;
use App\Research\Phase;
use App\Research\ResearchState;
use App\Security\DossierVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * The user's dossiers: the list, a dossier's page, and what the user does on a dossier
 * outside a run (rename, undo a rewrite, resume the research, export).
 */
final class DossierController extends AbstractController
{
    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly LockFactory $locks,
        private readonly NormalizerInterface $normalizer,
        private readonly DenormalizerInterface $denormalizer,
    ) {
    }

    #[Route('/', name: 'research', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('dossier/index.html.twig', [
            'dossiers' => $this->dossiers->ownedBy($this->getUser()?->getUserIdentifier() ?? ''),
        ]);
    }

    /** A new dossier is only an id until its first message: the stream creates it. */
    #[Route('/dossiers/new', name: 'dossier_new', methods: ['GET'])]
    public function new(): RedirectResponse
    {
        return $this->redirectToRoute('dossier_show', ['id' => (string) new Ulid()]);
    }

    #[Route('/dossiers/{id}', name: 'dossier_show', requirements: ['id' => Dossier::ID_PATTERN], methods: ['GET'])]
    public function show(string $id): Response
    {
        $dossier = $this->dossiers->find($id);
        if (null !== $dossier) {
            $this->denyAccessUnlessGranted(DossierVoter::OWN, $dossier);
        }

        $state = null === $dossier || [] === $dossier->getState() ? $this->normalizer->normalize(new ResearchState()) : $dossier->getState();
        $citations = [];
        foreach ([...($state['retained'] ?? []), ...array_map(static fn (array $d): array => ['id' => $d['id'], 'citation' => $d['title']], $state['dossier'] ?? [])] as $decision) {
            $citations[$decision['id']] = $decision['citation'];
        }

        return $this->render('dossier/show.html.twig', [
            'id' => $id,
            'dossier' => $dossier,
            'state' => $state,
            'citations' => $citations,
            'messages' => $dossier?->getMessages() ?? [],
            'interrupts' => $dossier?->getInterrupts() ?? [],
            'turns' => TranscriptView::turns($dossier?->getMessages() ?? [], $dossier?->getInterrupts() ?? []),
        ]);
    }

    /**
     * The saved state: the browser reads it after each run, since a tool that interrupts
     * the run changes the state without a STATE_DELTA.
     */
    #[Route('/dossiers/{id}/state', name: 'dossier_state', methods: ['GET'])]
    #[IsGranted(DossierVoter::OWN, 'dossier')]
    public function state(#[MapEntity] Dossier $dossier): JsonResponse
    {
        return new JsonResponse(['state' => $dossier->getState(), 'title' => $dossier->getTitle()]);
    }

    #[Route('/dossiers/{id}/rename', name: 'dossier_rename', methods: ['POST'])]
    #[IsGranted(DossierVoter::OWN, 'dossier')]
    #[IsCsrfTokenValid('dossier', tokenKey: '_token')]
    public function rename(#[MapEntity] Dossier $dossier, Request $request): RedirectResponse
    {
        $title = trim($request->getPayload()->getString('title'));
        if ('' !== $title) {
            $dossier->rename($title);
            $this->dossiers->save($dossier);
        }

        return $this->redirectToRoute('research');
    }

    /** Undoes the last rewrite of a section; answers the new state. */
    #[Route('/dossiers/{id}/sections/{key}/undo', name: 'dossier_section_undo', methods: ['POST'])]
    #[IsGranted(DossierVoter::OWN, 'dossier')]
    #[IsCsrfTokenValid('dossier', tokenKey: '_token')]
    public function undo(#[MapEntity] Dossier $dossier, string $key): JsonResponse
    {
        return $this->change($dossier, static function (ResearchState $state) use ($key): void {
            if (!\array_key_exists($key, Consultation::SECTIONS) || !$state->consultation?->undo($key)) {
                throw new NotFoundHttpException('Nothing to undo.');
            }
        });
    }

    /** Back to the research after delivery: the consultation stays, marked to update (ADR 0005). */
    #[Route('/dossiers/{id}/resume-research', name: 'dossier_resume_research', methods: ['POST'])]
    #[IsGranted(DossierVoter::OWN, 'dossier')]
    #[IsCsrfTokenValid('dossier', tokenKey: '_token')]
    public function resumeResearch(#[MapEntity] Dossier $dossier): JsonResponse
    {
        return $this->change($dossier, static function (ResearchState $state): void {
            if (!$state->phase->isOneOf([Phase::Livre, Phase::Redaction]) || null === $state->consultation) {
                throw new ConflictHttpException('The research can only resume once the consultation is written.');
            }
            $state->phase = Phase::Selection;
            $state->consultation->stale = true;
        });
    }

    #[Route('/dossiers/{id}/consultation.docx', name: 'dossier_docx', methods: ['GET'])]
    #[IsGranted(DossierVoter::OWN, 'dossier')]
    public function docx(#[MapEntity] Dossier $dossier, ConsultationExporter $exporter): Response
    {
        $bytes = $exporter->docx($dossier) ?? throw new NotFoundHttpException('No consultation yet.');
        $name = 'Consultation - '.('' === $dossier->getTitle() ? 'dossier' : $dossier->getTitle()).'.docx';

        return new Response($bytes, headers: [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, 'consultation.docx'),
        ]);
    }

    /** @param callable(ResearchState): void $change */
    private function change(Dossier $dossier, callable $change): JsonResponse
    {
        $lock = $this->locks->createLock('dossier-'.$dossier->getId(), 30.0);
        if (!$lock->acquire()) {
            throw new ConflictHttpException('Un run est déjà en cours sur ce dossier.');
        }

        try {
            $state = $this->denormalizer->denormalize($dossier->getState(), ResearchState::class);
            \assert($state instanceof ResearchState);
            $change($state);
            $dossier->recordState((array) $this->normalizer->normalize($state));
            $this->dossiers->save($dossier);

            return new JsonResponse(['state' => $dossier->getState()]);
        } finally {
            $lock->release();
        }
    }
}
