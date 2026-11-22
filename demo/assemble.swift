// Builds the demo video from demo/output/spec.json (written by demo/assemble.py):
// the frames become an H.264 track, each segment's voice-over starts with it, and its
// subtitle shows while it plays. Only AVFoundation: no ffmpeg needed.
//
// Usage: xcrun swift demo/assemble.swift demo/output/spec.json
import AVFoundation
import AppKit
import CoreImage
import QuartzCore

struct Segment: Decodable { let id: String; let start: Double; let duration: Double; let audio: String; let caption: String }
struct Spec: Decodable { let fps: Int32; let frames: String; let segments: [Segment]; let output: String }

let spec = try JSONDecoder().decode(Spec.self, from: Data(contentsOf: URL(fileURLWithPath: CommandLine.arguments[1])))
let size = CGSize(width: 1920, height: 1080)
let silent = URL(fileURLWithPath: spec.output).deletingLastPathComponent().appendingPathComponent("silent.mp4")
try? FileManager.default.removeItem(at: silent)

// 1. The frames, as an H.264 video.
let frames = try FileManager.default.contentsOfDirectory(atPath: spec.frames).filter { $0.hasSuffix(".png") }.sorted()
let writer = try AVAssetWriter(outputURL: silent, fileType: .mp4)
let input = AVAssetWriterInput(mediaType: .video, outputSettings: [
    AVVideoCodecKey: AVVideoCodecType.h264, AVVideoWidthKey: size.width, AVVideoHeightKey: size.height,
    AVVideoCompressionPropertiesKey: [AVVideoAverageBitRateKey: 8_000_000, AVVideoProfileLevelKey: AVVideoProfileLevelH264HighAutoLevel],
])
let adaptor = AVAssetWriterInputPixelBufferAdaptor(assetWriterInput: input, sourcePixelBufferAttributes: [
    kCVPixelBufferPixelFormatTypeKey as String: kCVPixelFormatType_32BGRA,
    kCVPixelBufferWidthKey as String: size.width, kCVPixelBufferHeightKey as String: size.height,
])
writer.add(input)
writer.startWriting()
writer.startSession(atSourceTime: .zero)
let context = CIContext()
for (i, name) in frames.enumerated() {
    while !input.isReadyForMoreMediaData { Thread.sleep(forTimeInterval: 0.005) }
    guard let image = CIImage(contentsOf: URL(fileURLWithPath: spec.frames).appendingPathComponent(name)),
          let pool = adaptor.pixelBufferPool else { continue }
    var buffer: CVPixelBuffer?
    CVPixelBufferPoolCreatePixelBuffer(nil, pool, &buffer)
    guard let pixels = buffer else { continue }
    context.render(image, to: pixels)
    adaptor.append(pixels, withPresentationTime: CMTime(value: CMTimeValue(i), timescale: spec.fps))
    if i % 500 == 0 { print("frames \(i)/\(frames.count)") }
}
input.markAsFinished()
await writer.finishWriting()
guard writer.status == .completed else { fatalError("Writing failed: \(String(describing: writer.error))") }

// 2. The voice-over, segment by segment.
let composition = AVMutableComposition()
let videoAsset = AVURLAsset(url: silent)
let sourceVideo = try await videoAsset.loadTracks(withMediaType: .video)[0]
let total = try await videoAsset.load(.duration)
let videoTrack = composition.addMutableTrack(withMediaType: .video, preferredTrackID: kCMPersistentTrackID_Invalid)!
try videoTrack.insertTimeRange(CMTimeRange(start: .zero, duration: total), of: sourceVideo, at: .zero)
let audioTrack = composition.addMutableTrack(withMediaType: .audio, preferredTrackID: kCMPersistentTrackID_Invalid)!
for segment in spec.segments {
    let voice = AVURLAsset(url: URL(fileURLWithPath: segment.audio))
    guard let track = try await voice.loadTracks(withMediaType: .audio).first else { continue }
    let length = try await voice.load(.duration)
    let at = CMTime(seconds: segment.start + 0.4, preferredTimescale: 600)
    let room = CMTimeSubtract(total, at)
    try audioTrack.insertTimeRange(CMTimeRange(start: .zero, duration: CMTimeMinimum(length, room)), of: track, at: at)
}

// 3. The subtitles, as a Core Animation overlay.
let parent = CALayer(); parent.frame = CGRect(origin: .zero, size: size)
let video = CALayer(); video.frame = parent.frame
parent.addSublayer(video)
for segment in spec.segments where !segment.caption.isEmpty {
    let font = NSFont.systemFont(ofSize: 30, weight: .medium)
    let style = NSMutableParagraphStyle(); style.alignment = .center; style.lineSpacing = 4
    let text = NSAttributedString(string: segment.caption, attributes: [.font: font, .foregroundColor: NSColor.white, .paragraphStyle: style])
    let width: CGFloat = 1500
    let bounds = text.boundingRect(with: CGSize(width: width, height: 400), options: [.usesLineFragmentOrigin])
    // Drawn into an image: a CATextLayer does not render in an export.
    let boxSize = CGSize(width: width + 60, height: ceil(bounds.height) + 36)
    let picture = NSImage(size: boxSize, flipped: false) { rect in
        NSColor(calibratedRed: 0.06, green: 0.05, blue: 0.16, alpha: 0.82).setFill()
        NSBezierPath(roundedRect: rect, xRadius: 18, yRadius: 18).fill()
        text.draw(with: CGRect(x: 30, y: 18, width: width, height: ceil(bounds.height)), options: [.usesLineFragmentOrigin])
        return true
    }
    var imageRect = CGRect(origin: .zero, size: boxSize)
    let box = CALayer()
    box.frame = CGRect(x: (size.width - boxSize.width) / 2, y: 44, width: boxSize.width, height: boxSize.height)
    box.contents = picture.cgImage(forProposedRect: &imageRect, context: nil, hints: [.ctm: NSAffineTransform(transform: AffineTransform(scale: 2))])
    box.opacity = 0
    let show = CAKeyframeAnimation(keyPath: "opacity")
    show.values = [0, 1, 1, 0]
    show.keyTimes = [0, 0.04, 0.96, 1]
    show.beginTime = AVCoreAnimationBeginTimeAtZero + segment.start + 0.2
    show.duration = max(1, segment.duration - 0.3)
    show.isRemovedOnCompletion = false
    box.add(show, forKey: "show")
    parent.addSublayer(box)
}
let videoComposition = AVMutableVideoComposition(propertiesOf: composition)
videoComposition.renderSize = size
videoComposition.frameDuration = CMTime(value: 1, timescale: spec.fps)
videoComposition.animationTool = AVVideoCompositionCoreAnimationTool(postProcessingAsVideoLayer: video, in: parent)

// 4. The MP4.
let output = URL(fileURLWithPath: spec.output)
try? FileManager.default.removeItem(at: output)
let export = AVAssetExportSession(asset: composition, presetName: AVAssetExportPreset1920x1080)!
export.videoComposition = videoComposition
try await export.export(to: output, as: .mp4)
try? FileManager.default.removeItem(at: silent)
print("Video: \(output.path) (\(String(format: "%.1f", CMTimeGetSeconds(total)))s)")
