<?php

namespace App\Controller\Stations\Reports;

use App\Config;
use App\Entity;
use App\Form\Form;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Service\MusicBrainz;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Produce a report in SoundExchange (the US webcaster licensing agency) format.
 */
class SoundExchangeController
{
    protected EntityManagerInterface $em;

    protected MusicBrainz $musicBrainz;

    protected array $form_config;

    public function __construct(EntityManagerInterface $em, MusicBrainz $musicBrainz, Config $config)
    {
        $this->em = $em;
        $this->musicBrainz = $musicBrainz;

        $this->form_config = $config->get('forms/report/soundexchange');
    }

    public function __invoke(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();

        $form = new Form($this->form_config);
        $form->populate([
            'start_date' => date('Y-m-d', strtotime('first day of last month')),
            'end_date' => date('Y-m-d', strtotime('last day of last month')),
        ]);

        if ($request->isPost() && $form->isValid($request->getParsedBody())) {
            $data = $form->getValues();

            $start_date = strtotime($data['start_date'] . ' 00:00:00');
            $end_date = strtotime($data['end_date'] . ' 23:59:59');

            $fetchIsrc = $data['fetch_isrc'];

            $export = [
                [
                    'NAME_OF_SERVICE',
                    'TRANSMISSION_CATEGORY',
                    'FEATURED_ARTIST',
                    'SOUND_RECORDING_TITLE',
                    'ISRC',
                    'ALBUM_TITLE',
                    'MARKETING_LABEL',
                    'ACTUAL_TOTAL_PERFORMANCES',
                ],
            ];

            $all_media = $this->em->createQuery(
                <<<'DQL'
                    SELECT PARTIAL sm.{
                        id,
                        unique_id,
                        art_updated_at,
                        path,
                        length,
                        length_text,
                        isrc,
                        artist,
                        title,
                        album,
                        genre
                    }, PARTIAL spm.{id}, PARTIAL sp.{id, name}, PARTIAL smcf.{id, field_id, value}
                    FROM App\Entity\StationMedia sm
                    LEFT JOIN sm.custom_fields smcf
                    LEFT JOIN sm.playlists spm
                    LEFT JOIN spm.playlist sp
                    WHERE sm.storage_location = :storageLocation
                    AND sp.station IS NULL OR sp.station = :station
                DQL
            )->setParameter('station', $station)
                ->setParameter('storageLocation', $station->getMediaStorageLocation())
                ->getArrayResult();

            $media_by_id = [];
            foreach ($all_media as $media_row) {
                $mediaId = $media_row['id'];
                $media_by_id[$mediaId] = $media_row;
            }

            $history_rows = $this->em->createQuery(
                <<<'DQL'
                    SELECT sh.song_id AS song_id, sh.text, sh.artist, sh.title, sh.media_id, COUNT(sh.id) AS plays,
                        SUM(sh.unique_listeners) AS unique_listeners
                    FROM App\Entity\SongHistory sh
                    WHERE sh.station = :station
                    AND sh.timestamp_start <= :time_end
                    AND sh.timestamp_end >= :time_start
                    GROUP BY sh.song_id
                DQL
            )->setParameter('station', $station)
                ->setParameter('time_start', $start_date)
                ->setParameter('time_end', $end_date)
                ->getArrayResult();

            $history_rows_by_id = [];
            foreach ($history_rows as $history_row) {
                $history_rows_by_id[$history_row['media_id']] = $history_row;
            }

            // Remove any reference to the "Stream Offline" song.
            $offlineSong = Entity\Song::createOffline();
            $offline_song_hash = $offlineSong->getSongId();
            unset($history_rows_by_id[$offline_song_hash]);

            // Assemble report items
            $station_name = $station->getName();

            $set_isrc_query = $this->em->createQuery(
                <<<'DQL'
                    UPDATE App\Entity\StationMedia sm
                    SET sm.isrc = :isrc
                    WHERE sm.id = :media_id
                DQL
            );

            foreach ($history_rows_by_id as $song_id => $history_row) {
                $song_row = $media_by_id[$song_id] ?? $history_row;

                // Try to find the ISRC if it's not already listed.
                if ($fetchIsrc && empty($song_row['isrc'])) {
                    $isrc = $this->findISRC($song_row);
                    $song_row['isrc'] = $isrc;

                    if (null !== $isrc && isset($song_row['media_id'])) {
                        $set_isrc_query->setParameter('isrc', $isrc)
                            ->setParameter('media_id', $song_row['media_id'])
                            ->execute();
                    }
                }

                $export[] = [
                    $station_name,
                    'A',
                    $song_row['artist'] ?? '',
                    $song_row['title'] ?? '',
                    $song_row['isrc'] ?? '',
                    $song_row['album'] ?? '',
                    '',
                    $history_row['unique_listeners'],
                ];
            }

            // Assemble export into SoundExchange format
            $export_txt_raw = [];
            foreach ($export as $export_row) {
                foreach ($export_row as $i => $export_col) {
                    if (!is_numeric($export_col)) {
                        $export_row[$i] = '^' . str_replace(['^', '|'], ['', ''], strtoupper($export_col)) . '^';
                    }
                }
                $export_txt_raw[] = implode('|', $export_row);
            }
            $export_txt = implode("\n", $export_txt_raw);

            // Example: WABC01012009-31012009_A.txt
            $export_filename = strtoupper($station->getShortName())
                . date('dmY', $start_date) . '-'
                . date('dmY', $end_date) . '_A.txt';

            return $response->renderStringAsFile($export_txt, 'text/plain', $export_filename);
        }

        return $request->getView()->renderToResponse($response, 'system/form_page', [
            'form' => $form,
            'render_mode' => 'edit',
            'title' => __('SoundExchange Report'),
        ]);
    }

    protected function findISRC($song_row): ?string
    {
        $song = Entity\Song::createFromArray($song_row);

        try {
            foreach ($this->musicBrainz->findRecordingsForSong($song, 'isrcs') as $recording) {
                if (!empty($recording['isrcs'])) {
                    return $recording['isrcs'][0];
                }
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
