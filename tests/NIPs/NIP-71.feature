Feature: NIP-71 Video Events
  As a nostr application developer
  I want to create video content events
  So that videos can contribute to the multi-media experience

  Background:
    Given I have a valid nostr keypair
    And I am connected to a relay

  Scenario: Creating a basic normal video event
    Given I create a video event with kind 21
    And I set a title tag with "My First Video"
    And I add a description in the content
    And I include an imeta tag with video URL "https://myvideo.com/1080/12345.mp4"
    And I set the video mime type to "video/mp4"
    And I set the video dimensions to "1920x1080"
    And I include a preview image "https://myvideo.com/1080/12345.jpg"
    When I publish the video event
    Then the event should be stored and retrievable
    And the video should be self-contained with external hosting

  Scenario: Creating a short-form video event
    Given I create a video event with kind 22
    And I set a title tag with "My Short Story"
    And I add a description in the content
    And I include an imeta tag with video URL "https://myvideo.com/shorts/67890.mp4"
    And I set the video mime type to "video/mp4"
    And I set the video dimensions to "1080x1920"
    When I publish the video event
    Then the event should be stored as a short-form video
    And clients should display it in a vertical format

  Scenario: Video event with multiple quality variants
    Given I create a video event with kind 21
    And I set a title tag
    And I include an imeta tag for 1920x1080 resolution with URL "https://myvideo.com/1080/12345.mp4"
    And I include an imeta tag for 1280x720 resolution with URL "https://myvideo.com/720/12345.mp4"
    And I include an imeta tag for 1280x720 HLS stream with URL "https://myvideo.com/720/12345.m3u8"
    And each variant has a SHA-256 hash using "x" parameter
    When I publish the video event
    Then all video variants should be available
    And each variant should have its own imeta tag with dimensions

  Scenario: Video with complete metadata
    Given I create a video event with kind 21
    And I include an imeta tag with URL, mime type, dimensions, and SHA-256 hash
    And I add a preview image for the video
    And I add an alt text for accessibility
    And I provide fallback URLs
    And I include "service nip96" in the imeta tag
    And I set a duration tag with "300" seconds
    And I set a published_at timestamp
    When I publish the video event
    Then the video metadata should be complete and queryable

  Scenario: Video with fallback servers
    Given I create a video event with kind 21
    And I set the primary video URL to "https://myvideo.com/1080/12345.mp4"
    And I add fallback URLs "https://myotherserver.com/1080/12345.mp4"
    And I add fallback URLs "https://andanotherserver.com/1080/12345.mp4"
    And I add preview image fallbacks
    When I publish the video event
    Then clients should be able to use any of the provided URLs equally

  Scenario: Tagging participants in videos
    Given I create a video event with kind 21
    And I include p tags for multiple participants
    And I add recommended relay URLs for each participant
    When I publish the video event
    Then tagged participants should be linked to the video
    And users should be notified of being tagged

  Scenario: NSFW content warning for video
    Given I create a video event with sensitive content
    And I add a content-warning tag with reason
    When I publish the video event
    Then clients should display a content warning before showing the video

  Scenario: Video with hashtags
    Given I create a video event with kind 21
    And I add multiple t tags for hashtags
    When I publish the video event
    Then the video should be discoverable by hashtags

  Scenario: Video with text tracks (captions/subtitles)
    Given I create a video event with kind 21
    And I add a text-track tag linking to WebVTT file "https://myvideo.com/captions/en.vtt"
    And I specify the track type as "captions"
    And I specify the language code as "en"
    When I publish the video event
    Then the video should support captions and subtitles

  Scenario: Video with chapter segments
    Given I create a video event with kind 21
    And I add a segment tag with start "00:00:00.000", end "00:05:30.000", title "Introduction"
    And I add a segment tag with start "00:05:30.000", end "00:15:45.000", title "Main Content"
    And I include thumbnail URLs for each segment
    When I publish the video event
    Then the video should have navigable chapters

  Scenario: Video with reference links
    Given I create a video event with kind 21
    And I add multiple r tags with reference URLs
    When I publish the video event
    Then the reference links should be associated with the video

  Scenario: Supported video formats
    Given I create a video event with kind 21
    When I try to use a video with mime type "<mime_type>"
    Then the event should accept valid video types
    Examples:
      | mime_type              |
      | video/mp4              |
      | video/webm             |
      | video/ogg              |
      | video/quicktime        |
      | application/x-mpegURL  |

  Scenario: Video with NIP-96 service integration
    Given I create a video event with kind 21
    And I include "service nip96" in the imeta tag
    And I include SHA-256 hash for the video
    When I publish the video event
    Then clients should be able to search the author's NIP-96 server list
    And the file should be findable using the hash

  Scenario: Queryable video hashes
    Given I create a video event with multiple variants
    And I include x tags with SHA-256 hashes for each variant
    When I publish the video event
    Then the videos should be queryable by their hashes

  Scenario: Video with published timestamp
    Given I create a video event with kind 21
    And I set a published_at tag with the first publication timestamp
    When I publish the video event
    Then the original publication time should be preserved
    And it should differ from the created_at timestamp if republished

  Scenario: Complete video event structure
    Given I create a video event with kind 21
    And I set a title tag with "Complete Tutorial Video"
    And I add a summary in the content field
    And I set a published_at timestamp
    And I add an alt text description
    And I include imeta tags for multiple quality variants
    And I set a duration tag
    And I add text-track tags for captions
    And I add a content-warning if needed
    And I add segment tags for chapters
    And I include p tags for participants
    And I add t tags for hashtags
    And I add r tags for reference links
    When I publish the video event
    Then all metadata should be properly structured
    And the event should be fully compatible with video-specific clients

